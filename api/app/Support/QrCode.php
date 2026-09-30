<?php

namespace App\Support;

use RuntimeException;

/**
 * Générateur de QR code (ISO/IEC 18004), mode octet et correction d'erreur M.
 *
 * Écrit ici faute de bibliothèque : aucun paquet de QR n'est installé côté API et la consigne
 * interdit d'en ajouter. Le SPA, lui, utilise `qrcode` (npm) — inutilisable depuis PHP. Les
 * tests comparent la matrice produite à celle de cette même bibliothèque npm (vecteurs figés
 * dans tests/Fixtures/qr-vectors.json), version, masque et modules compris.
 *
 * Volontairement limité à ce dont l'application a besoin :
 * - un seul segment, en mode octet (les URL des événements contiennent des minuscules) ;
 * - niveau de correction M (~15 %), celui du SPA ;
 * - versions 1 à 20, soit 666 octets — très au-delà d'une URL d'événement.
 *
 * La sortie est matricielle (`matrix()`), voir QrPng pour l'image.
 */
final class QrCode
{
    /** Niveau de correction d'erreur, identique à celui du SPA. */
    public const ERROR_CORRECTION = 'M';

    public const MAX_VERSION = 20;

    /** Indicateur du mode octet (4 bits). */
    private const MODE_BYTE = 0b0100;

    /** Polynôme primitif du corps de Galois GF(256) utilisé par la norme. */
    private const GF_PRIMITIVE = 0x11D;

    /**
     * Correction d'erreur de niveau M, par version :
     * [codewords de correction par bloc, blocs du groupe 1, données par bloc du groupe 1,
     *  blocs du groupe 2, données par bloc du groupe 2].
     *
     * @var array<int, array{int, int, int, int, int}>
     */
    private const EC_BLOCKS = [
        1 => [10, 1, 16, 0, 0],
        2 => [16, 1, 28, 0, 0],
        3 => [26, 1, 44, 0, 0],
        4 => [18, 2, 32, 0, 0],
        5 => [24, 2, 43, 0, 0],
        6 => [16, 4, 27, 0, 0],
        7 => [18, 4, 31, 0, 0],
        8 => [22, 2, 38, 2, 39],
        9 => [22, 3, 36, 2, 37],
        10 => [26, 4, 43, 1, 44],
        11 => [30, 1, 50, 4, 51],
        12 => [22, 6, 36, 2, 37],
        13 => [22, 8, 37, 1, 38],
        14 => [24, 4, 40, 5, 41],
        15 => [24, 5, 41, 5, 42],
        16 => [28, 7, 45, 3, 46],
        17 => [28, 10, 46, 1, 47],
        18 => [26, 9, 43, 4, 44],
        19 => [26, 3, 44, 11, 45],
        20 => [26, 3, 41, 13, 42],
    ];

    /**
     * Centres des motifs d'alignement, par version.
     *
     * @var array<int, list<int>>
     */
    private const ALIGNMENT = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
        11 => [6, 30, 54],
        12 => [6, 32, 58],
        13 => [6, 34, 62],
        14 => [6, 26, 46, 66],
        15 => [6, 26, 48, 70],
        16 => [6, 26, 50, 74],
        17 => [6, 30, 54, 78],
        18 => [6, 30, 56, 82],
        19 => [6, 30, 58, 86],
        20 => [6, 34, 62, 90],
    ];

    /** Bits restants après les codewords, par version (0, 7 ou 3). */
    private const REMAINDER_BITS = [
        1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7,
        7 => 0, 8 => 0, 9 => 0, 10 => 0, 11 => 0, 12 => 0, 13 => 0,
        14 => 3, 15 => 3, 16 => 3, 17 => 3, 18 => 3, 19 => 3, 20 => 3,
    ];

    /**
     * @param  list<list<bool>>  $modules  matrice carrée, true = module sombre
     */
    private function __construct(
        public readonly int $version,
        public readonly int $mask,
        private readonly array $modules,
    ) {}

    /**
     * Encode un texte (UTF-8, mode octet) dans la plus petite version qui le contient.
     *
     * @throws RuntimeException si le texte dépasse la capacité de la version 20
     */
    public static function encode(string $text): self
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        $version = self::smallestVersion(count($bytes));
        $codewords = self::interleave($version, self::dataCodewords($version, $bytes));

        $reserved = self::functionModules($version);
        $modules = self::placeData($version, $reserved, $codewords);

        return self::withBestMask($version, $modules, $reserved);
    }

    /**
     * Nombre de modules d'un côté (21 pour la version 1, +4 par version).
     */
    public function size(): int
    {
        return count($this->modules);
    }

    public function isDark(int $row, int $column): bool
    {
        return $this->modules[$row][$column] ?? false;
    }

    /**
     * @return list<list<bool>>
     */
    public function matrix(): array
    {
        return $this->modules;
    }

    // --- Encodage des données ----------------------------------------------------------

    /**
     * Plus petite version dont la capacité en mode octet contient $length octets.
     */
    private static function smallestVersion(int $length): int
    {
        for ($version = 1; $version <= self::MAX_VERSION; $version++) {
            $bits = self::dataCodewordCount($version) * 8;
            $overhead = 4 + self::lengthBits($version);

            if ($length * 8 + $overhead <= $bits) {
                return $version;
            }
        }

        throw new RuntimeException(
            'Texte trop long pour un QR code de niveau M (limite : version '.self::MAX_VERSION.')'
        );
    }

    /** Longueur de l'indicateur de nombre de caractères, en mode octet. */
    private static function lengthBits(int $version): int
    {
        return $version <= 9 ? 8 : 16;
    }

    private static function dataCodewordCount(int $version): int
    {
        [, $blocks1, $data1, $blocks2, $data2] = self::EC_BLOCKS[$version];

        return $blocks1 * $data1 + $blocks2 * $data2;
    }

    /**
     * Segment de données : mode, longueur, octets, terminateur, remplissage (0xEC / 0x11).
     *
     * @param  list<int>  $bytes
     * @return list<int>
     */
    private static function dataCodewords(int $version, array $bytes): array
    {
        $capacity = self::dataCodewordCount($version) * 8;
        $bits = [];

        self::appendBits($bits, self::MODE_BYTE, 4);
        self::appendBits($bits, count($bytes), self::lengthBits($version));

        foreach ($bytes as $byte) {
            self::appendBits($bits, $byte, 8);
        }

        // Terminateur (jusqu'à 4 zéros) puis alignement sur l'octet.
        self::appendBits($bits, 0, min(4, $capacity - count($bits)));
        self::appendBits($bits, 0, (8 - count($bits) % 8) % 8);

        $codewords = [];

        for ($i = 0; $i < count($bits); $i += 8) {
            $byte = 0;

            for ($j = 0; $j < 8; $j++) {
                $byte = ($byte << 1) | $bits[$i + $j];
            }

            $codewords[] = $byte;
        }

        // Octets de remplissage normalisés, en alternance.
        foreach ([0xEC, 0x11] as $pad) {
            while (count($codewords) < $capacity / 8) {
                $codewords[] = $pad;
                $pad = $pad === 0xEC ? 0x11 : 0xEC;
            }
        }

        return $codewords;
    }

    /**
     * @param  list<int>  $bits
     */
    private static function appendBits(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    /**
     * Découpe en blocs, calcule la correction d'erreur puis entrelace données et correction.
     *
     * @param  list<int>  $codewords
     * @return list<int>
     */
    private static function interleave(int $version, array $codewords): array
    {
        [$ecLength, $blocks1, $data1, $blocks2, $data2] = self::EC_BLOCKS[$version];
        $divisor = self::rsDivisor($ecLength);

        $dataBlocks = [];
        $ecBlocks = [];
        $offset = 0;

        foreach ([[$blocks1, $data1], [$blocks2, $data2]] as [$count, $size]) {
            for ($i = 0; $i < $count; $i++) {
                $block = array_slice($codewords, $offset, $size);
                $offset += $size;
                $dataBlocks[] = $block;
                $ecBlocks[] = self::rsRemainder($block, $divisor);
            }
        }

        $result = [];
        $longest = max(array_map('count', $dataBlocks));

        for ($i = 0; $i < $longest; $i++) {
            foreach ($dataBlocks as $block) {
                if ($i < count($block)) {
                    $result[] = $block[$i];
                }
            }
        }

        for ($i = 0; $i < $ecLength; $i++) {
            foreach ($ecBlocks as $block) {
                $result[] = $block[$i];
            }
        }

        return $result;
    }

    /**
     * Polynôme générateur de Reed-Solomon de degré $degree.
     *
     * @return list<int>
     */
    private static function rsDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;

        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMultiply($result[$j], $root);

                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }

            $root = self::gfMultiply($root, 0x02);
        }

        return $result;
    }

    /**
     * Reste de la division polynomiale : les codewords de correction d'un bloc.
     *
     * @param  list<int>  $data
     * @param  list<int>  $divisor
     * @return list<int>
     */
    private static function rsRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);

        foreach ($data as $byte) {
            $factor = $byte ^ (int) array_shift($result);
            $result[] = 0;

            foreach ($divisor as $i => $coefficient) {
                $result[$i] ^= self::gfMultiply($coefficient, $factor);
            }
        }

        return array_values($result);
    }

    /** Multiplication dans GF(256). */
    private static function gfMultiply(int $x, int $y): int
    {
        $z = 0;

        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * self::GF_PRIMITIVE);
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z;
    }

    // --- Construction de la matrice ----------------------------------------------------

    /**
     * Matrice des motifs fonctionnels : valeur du module, ou null pour une case de données.
     * Les zones réservées au format et à la version y sont marquées `false` (fixées plus tard).
     *
     * @return list<list<bool|null>>
     */
    private static function functionModules(int $version): array
    {
        $size = 17 + 4 * $version;
        /** @var list<list<bool|null>> $modules */
        $modules = array_fill(0, $size, array_fill(0, $size, null));

        // Motifs de repérage et leurs séparateurs (bloc de 9 × 9 aux trois coins).
        foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$top, $left]) {
            for ($row = $top - 1; $row <= $top + 7; $row++) {
                for ($column = $left - 1; $column <= $left + 7; $column++) {
                    if ($row < 0 || $column < 0 || $row >= $size || $column >= $size) {
                        continue;
                    }

                    $distance = max(abs($row - $top - 3), abs($column - $left - 3));
                    $modules[$row][$column] = $distance !== 2 && $distance <= 3;
                }
            }
        }

        // Motifs d'alignement, sauf ceux qui recouvriraient un motif de repérage.
        $centres = self::ALIGNMENT[$version];
        $last = count($centres) - 1;

        foreach ($centres as $i => $row) {
            foreach ($centres as $j => $column) {
                $corner = ($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0);

                if ($corner) {
                    continue;
                }

                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $modules[$row + $dy][$column + $dx] = max(abs($dy), abs($dx)) !== 1;
                    }
                }
            }
        }

        // Motifs de synchronisation (ligne et colonne 6).
        for ($i = 8; $i < $size - 8; $i++) {
            $modules[6][$i] = $i % 2 === 0;
            $modules[$i][6] = $i % 2 === 0;
        }

        // Zones réservées au format (autour des trois repères) et module toujours sombre.
        for ($i = 0; $i <= 8; $i++) {
            $modules[8][$i] ??= false;
            $modules[$i][8] ??= false;
        }

        for ($i = 0; $i < 8; $i++) {
            $modules[8][$size - 1 - $i] ??= false;
            $modules[$size - 1 - $i][8] ??= false;
        }

        $modules[$size - 8][8] = true;

        // Zone réservée à la version (deux blocs de 3 × 6), à partir de la version 7.
        if ($version >= 7) {
            for ($i = 0; $i < 18; $i++) {
                $a = $size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $modules[$b][$a] ??= false;
                $modules[$a][$b] ??= false;
            }
        }

        return $modules;
    }

    /**
     * Place les codewords en zigzag depuis le coin inférieur droit, sans toucher aux
     * motifs fonctionnels ; la colonne 6 (synchronisation verticale) est sautée.
     *
     * @param  list<list<bool|null>>  $reserved
     * @param  list<int>  $codewords
     * @return list<list<bool>>
     */
    private static function placeData(int $version, array $reserved, array $codewords): array
    {
        $size = count($reserved);
        $bits = [];

        foreach ($codewords as $codeword) {
            self::appendBits($bits, $codeword, 8);
        }

        self::appendBits($bits, 0, self::REMAINDER_BITS[$version]);

        /** @var list<list<bool>> $modules */
        $modules = array_fill(0, $size, array_fill(0, $size, false));

        foreach ($reserved as $row => $line) {
            foreach ($line as $column => $value) {
                if ($value !== null) {
                    $modules[$row][$column] = $value;
                }
            }
        }

        $index = 0;
        $upward = true;

        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }

            for ($step = 0; $step < $size; $step++) {
                for ($offset = 0; $offset < 2; $offset++) {
                    $column = $right - $offset;
                    $row = $upward ? $size - 1 - $step : $step;

                    if ($reserved[$row][$column] !== null) {
                        continue;
                    }

                    $modules[$row][$column] = ($bits[$index] ?? 0) === 1;
                    $index++;
                }
            }

            $upward = ! $upward;
        }

        return $modules;
    }

    /**
     * Applique les huit masques et retient celui dont la pénalité est la plus faible
     * (règles 1 à 4 de la norme).
     *
     * @param  list<list<bool>>  $modules
     * @param  list<list<bool|null>>  $reserved
     */
    private static function withBestMask(int $version, array $modules, array $reserved): self
    {
        $best = null;
        $bestPenalty = PHP_INT_MAX;
        $bestMask = 0;

        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = self::writeVersion(
                self::writeFormat(self::applyMask($modules, $reserved, $mask), $mask),
                $version,
            );
            $penalty = self::penalty($candidate);

            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $candidate;
                $bestMask = $mask;
            }
        }

        /** @var list<list<bool>> $best */
        return new self($version, $bestMask, $best);
    }

    /**
     * @param  list<list<bool>>  $modules
     * @param  list<list<bool|null>>  $reserved
     * @return list<list<bool>>
     */
    private static function applyMask(array $modules, array $reserved, int $mask): array
    {
        foreach ($modules as $row => $line) {
            foreach ($line as $column => $dark) {
                if ($reserved[$row][$column] !== null) {
                    continue;
                }

                $modules[$row][$column] = $dark !== self::maskBit($mask, $row, $column);
            }
        }

        return $modules;
    }

    private static function maskBit(int $mask, int $row, int $column): bool
    {
        return match ($mask) {
            0 => ($row + $column) % 2 === 0,
            1 => $row % 2 === 0,
            2 => $column % 3 === 0,
            3 => ($row + $column) % 3 === 0,
            4 => (intdiv($row, 2) + intdiv($column, 3)) % 2 === 0,
            5 => ($row * $column) % 2 + ($row * $column) % 3 === 0,
            6 => (($row * $column) % 2 + ($row * $column) % 3) % 2 === 0,
            default => ((($row + $column) % 2) + ($row * $column) % 3) % 2 === 0,
        };
    }

    /**
     * Information de format : niveau M (0b00) + masque, protégés par un code BCH(15, 5).
     *
     * @param  list<list<bool>>  $modules
     * @return list<list<bool>>
     */
    private static function writeFormat(array $modules, int $mask): array
    {
        $size = count($modules);
        $data = (0b00 << 3) | $mask;
        $remainder = $data;

        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ ((($remainder >> 9) & 1) * 0b10100110111);
        }

        $bits = (($data << 10) | $remainder) ^ 0b101010000010010;
        $bit = static fn (int $i): bool => (($bits >> $i) & 1) === 1;

        for ($i = 0; $i <= 5; $i++) {
            $modules[$i][8] = $bit($i);
        }

        $modules[7][8] = $bit(6);
        $modules[8][8] = $bit(7);
        $modules[8][7] = $bit(8);

        for ($i = 9; $i < 15; $i++) {
            $modules[8][14 - $i] = $bit($i);
        }

        for ($i = 0; $i < 8; $i++) {
            $modules[8][$size - 1 - $i] = $bit($i);
        }

        for ($i = 8; $i < 15; $i++) {
            $modules[$size - 15 + $i][8] = $bit($i);
        }

        $modules[$size - 8][8] = true;

        return $modules;
    }

    /**
     * Information de version (versions 7 et suivantes), code BCH(18, 6).
     *
     * @param  list<list<bool>>  $modules
     * @return list<list<bool>>
     */
    private static function writeVersion(array $modules, int $version): array
    {
        if ($version < 7) {
            return $modules;
        }

        $size = count($modules);
        $remainder = $version;

        for ($i = 0; $i < 12; $i++) {
            $remainder = ($remainder << 1) ^ ((($remainder >> 11) & 1) * 0b1111100100101);
        }

        $bits = ($version << 12) | $remainder;

        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) === 1;
            $a = $size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $modules[$b][$a] = $dark;
            $modules[$a][$b] = $dark;
        }

        return $modules;
    }

    // --- Pénalités de masquage (norme, § 8.8.2) -----------------------------------------

    /**
     * @param  list<list<bool>>  $modules
     */
    private static function penalty(array $modules): int
    {
        return self::penaltyRuns($modules)
            + self::penaltyBlocks($modules)
            + self::penaltyFinderLike($modules)
            + self::penaltyBalance($modules);
    }

    /**
     * Règle 1 : suites de 5 modules de même couleur ou plus (3 points, +1 par module au-delà).
     *
     * @param  list<list<bool>>  $modules
     */
    private static function penaltyRuns(array $modules): int
    {
        $penalty = 0;

        foreach (self::lines($modules) as $line) {
            $run = 1;

            for ($i = 1; $i < count($line); $i++) {
                if ($line[$i] === $line[$i - 1]) {
                    $run++;

                    continue;
                }

                $penalty += $run >= 5 ? $run - 2 : 0;
                $run = 1;
            }

            $penalty += $run >= 5 ? $run - 2 : 0;
        }

        return $penalty;
    }

    /**
     * Règle 2 : chaque carré de 2 × 2 modules de même couleur vaut 3 points.
     *
     * @param  list<list<bool>>  $modules
     */
    private static function penaltyBlocks(array $modules): int
    {
        $size = count($modules);
        $penalty = 0;

        for ($row = 0; $row < $size - 1; $row++) {
            for ($column = 0; $column < $size - 1; $column++) {
                $value = $modules[$row][$column];

                if ($value === $modules[$row][$column + 1]
                    && $value === $modules[$row + 1][$column]
                    && $value === $modules[$row + 1][$column + 1]) {
                    $penalty += 3;
                }
            }
        }

        return $penalty;
    }

    /**
     * Règle 3 : motif 1:1:3:1:1 précédé ou suivi de quatre modules clairs (40 points).
     *
     * @param  list<list<bool>>  $modules
     */
    private static function penaltyFinderLike(array $modules): int
    {
        $patterns = [
            [true, false, true, true, true, false, true, false, false, false, false],
            [false, false, false, false, true, false, true, true, true, false, true],
        ];
        $penalty = 0;

        foreach (self::lines($modules) as $line) {
            $length = count($line);

            for ($i = 0; $i + 11 <= $length; $i++) {
                $window = array_slice($line, $i, 11);

                foreach ($patterns as $pattern) {
                    if ($window === $pattern) {
                        $penalty += 40;
                    }
                }
            }
        }

        return $penalty;
    }

    /**
     * Règle 4 : écart de la proportion de modules sombres à 50 % (10 points par tranche de 5 %).
     *
     * @param  list<list<bool>>  $modules
     */
    private static function penaltyBalance(array $modules): int
    {
        $size = count($modules);
        $dark = 0;

        foreach ($modules as $line) {
            foreach ($line as $value) {
                $dark += $value ? 1 : 0;
            }
        }

        $ratio = $dark * 100 / ($size * $size);

        return (int) (abs($ratio - 50) / 5) * 10;
    }

    /**
     * Toutes les lignes puis toutes les colonnes, pour les règles 1 et 3.
     *
     * @param  list<list<bool>>  $modules
     * @return list<list<bool>>
     */
    private static function lines(array $modules): array
    {
        $lines = $modules;
        $size = count($modules);

        for ($column = 0; $column < $size; $column++) {
            $lines[] = array_column($modules, $column);
        }

        return $lines;
    }
}
