/**
 * exportHelpers.js
 * Fonctions d'export : CSV, Excel (complet), PDF
 * À importer dans VisitorsPage.jsx
 */

const SOURCE_LABELS = {
  invite_membre:'Invité par un membre', saint_esprit:'Saint Esprit',
  reseaux_sociaux:'Réseaux sociaux', affiche_tract:'Affiche / Tract',
  bouche_a_oreille:'Bouche à oreille', passage:'Passage devant l\'église', autre:'Autre',
};
const REASON_LABELS = {
  enseignement:'Enseignement de la Parole', chaleur_fraternelle:'Chaleur fraternelle',
  louange_adoration:'Louange & adoration', accueil:'Accueil reçu', autres:'Autre(s)',
};
const STATUS_LABELS = {
  prospect:'Prospect', recurrent:'Récurrent',
  membre_potentiel:'Membre potentiel', membre:'Membre',
};

const fmtDate = (d) => d ? new Date(d).toLocaleDateString('fr-FR') : '';
const yesNo   = (v) => v ? 'Oui' : 'Non';

/* ─── Transformation visiteur → ligne plate ─────────────────────── */
const flattenVisitor = (v) => ({
  // ── Identité ──
  'Nom & Prénoms':        v.visit1?.fullName || v.visit2?.fullName || '—',
  'Téléphone':            v.phone,
  'Statut':               STATUS_LABELS[v.status] || v.status,
  'Nb visites':           v.visitCount,
  'Date inscription':     fmtDate(v.createdAt),

  // ── Formulaire 1 ──
  'F1 – Date':            fmtDate(v.visit1?.date),
  'F1 – WhatsApp':        v.visit1?.whatsapp        || '',
  'F1 – Autre contact':   v.visit1?.contactPhone    || '',
  'F1 – Commune':         v.visit1?.city            || '',
  'F1 – Quartier':        v.visit1?.neighborhood    || '',
  'F1 – Source':          SOURCE_LABELS[v.visit1?.inviteSource] || v.visit1?.inviteSource || '',
  'F1 – Invité par':      v.visit1?.invitedBy       || '',
  'F1 – Congrégation':    v.visit1?.congregation    || '',
  'F1 – Groupe WA':       v.visit1 ? yesNo(v.visit1.wantsWhatsAppGroup) : '',
  'F1 – Famille accueil': v.visit1?.familleAccueil  || '',
  'F1 – Notes':           v.visit1?.notes           || '',

  // ── Formulaire 2 ──
  'F2 – Date':            fmtDate(v.visit2?.date),
  'F2 – Raisons retour':  v.visit2?.returnReasons?.map(r => REASON_LABELS[r] || r).join(' | ') || '',
  'F2 – Précision':       v.visit2?.returnReasonsOther || '',
  'F2 – Famille accueil': v.visit2?.familleAccueil  || '',

  // ── Formulaire 3 ──
  'F3 – Date':            fmtDate(v.visit3?.date),
  'F3 – Raison visite':   v.visit3?.visitReason     || '',
  'F3 – Famille accueil': v.visit3?.familleAccueil  || '',

  // ── Conversion membre ──
  'Converti membre le':   fmtDate(v.convertedToMemberAt),
});

/* ─── Export CSV ─────────────────────────────────────────────────── */
export const exportCSV = (visitors) => {
  const rows    = visitors.map(flattenVisitor);
  const headers = Object.keys(rows[0] || {});
  const csv     = [headers, ...rows.map(r => headers.map(h => `"${(r[h]||'').replace(/"/g,'""')}"`))]
    .map(r => r.join(',')).join('\n');
  const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href = url;
  a.download = `visiteurs_${new Date().toISOString().slice(0,10)}.csv`;
  a.click();
  URL.revokeObjectURL(url);
};

/* ─── Export Excel ───────────────────────────────────────────────── */
const loadSheetJS = () => new Promise((resolve, reject) => {
  if (window.XLSX) { resolve(window.XLSX); return; }
  const s = document.createElement('script');
  s.src     = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
  s.onload  = () => resolve(window.XLSX);
  s.onerror = reject;
  document.head.appendChild(s);
});

export const exportExcel = async (visitors) => {
  const XLSX = await loadSheetJS();
  const rows = visitors.map(flattenVisitor);

  if (rows.length === 0) { alert('Aucun visiteur à exporter.'); return; }

  const ws = XLSX.utils.json_to_sheet(rows);

  // Largeurs colonnes adaptées
  const colWidths = Object.keys(rows[0]).map(key => {
    const maxLen = Math.max(key.length, ...rows.map(r => String(r[key] || '').length));
    return { wch: Math.min(Math.max(maxLen + 2, 10), 40) };
  });
  ws['!cols'] = colWidths;

  // Style en-têtes (couleur violette) — nécessite xlsx-style ou feuille simple
  const range = XLSX.utils.decode_range(ws['!ref']);
  for (let C = range.s.c; C <= range.e.c; C++) {
    const cellAddr = XLSX.utils.encode_cell({ r: 0, c: C });
    if (!ws[cellAddr]) continue;
    ws[cellAddr].s = {
      font:      { bold: true, color: { rgb: 'FFFFFF' } },
      fill:      { fgColor: { rgb: '6B1F8A' } },
      alignment: { horizontal: 'center', vertical: 'center' },
    };
  }

  // Figer la première ligne
  ws['!freeze'] = { xSplit: 0, ySplit: 1 };

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Visiteurs');

  // Feuille récap
  const recap = [
    { 'Informations': 'Total visiteurs',         'Valeur': visitors.length },
    { 'Informations': 'Prospects',               'Valeur': visitors.filter(v => v.status === 'prospect').length },
    { 'Informations': 'Récurrents',              'Valeur': visitors.filter(v => v.status === 'recurrent').length },
    { 'Informations': 'Membres potentiels',      'Valeur': visitors.filter(v => v.status === 'membre_potentiel').length },
    { 'Informations': 'Membres confirmés',       'Valeur': visitors.filter(v => v.status === 'membre').length },
    { 'Informations': 'Exporté le',              'Valeur': new Date().toLocaleDateString('fr-FR') },
  ];
  const wsRecap = XLSX.utils.json_to_sheet(recap);
  wsRecap['!cols'] = [{ wch: 25 }, { wch: 15 }];
  XLSX.utils.book_append_sheet(wb, wsRecap, 'Récapitulatif');

  XLSX.writeFile(wb, `visiteurs_complet_${new Date().toISOString().slice(0,10)}.xlsx`);
};

/* ─── Export PDF (impression navigateur) ───────────────────────── */
export const exportPDF = (visitors) => {
  const rows = visitors.map(flattenVisitor);

  // On sélectionne les colonnes les plus importantes pour le PDF
  const pdfCols = [
    'Nom & Prénoms','Téléphone','F1 – Commune','F1 – Quartier',
    'Statut','Nb visites','F1 – Famille accueil','F2 – Famille accueil','F3 – Famille accueil',
    'F1 – Source','F1 – Invité par','Date inscription',
  ];

  const thead = pdfCols.map(h => `<th>${h}</th>`).join('');
  const tbody = rows.map((r, i) => `
    <tr style="background:${i%2===0?'#fff':'#f9f7ff'}">
      ${pdfCols.map(h => `<td>${r[h] || ''}</td>`).join('')}
    </tr>`).join('');

  const html = `<!DOCTYPE html><html><head>
    <meta charset="UTF-8"/>
    <title>Visiteurs — Eglise La Maison de la Destinée</title>
    <style>
      @page { size: A4 landscape; margin: 15mm; }
      body  { font-family: Arial, sans-serif; font-size: 10px; color: #1f2937; margin: 0; }
      h1    { color: #6B1F8A; font-size: 16px; margin: 0 0 4px; }
      .sub  { color: #6b7280; font-size: 11px; margin: 0 0 14px; }
      table { width: 100%; border-collapse: collapse; }
      thead tr { background: #6B1F8A; }
      th    { color: white; padding: 6px 8px; text-align: left; font-weight: 700; font-size: 9px; white-space: nowrap; }
      td    { padding: 5px 8px; border-bottom: 1px solid #f0ebff; vertical-align: top; }
      @media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style></head><body>
    <h1>🏛️ Registre des visiteurs — La Maison de la Destinée</h1>
    <p class="sub">Exporté le ${new Date().toLocaleDateString('fr-FR',{day:'numeric',month:'long',year:'numeric'})} · ${visitors.length} visiteur(s) · Toutes les données</p>
    <table>
      <thead><tr>${thead}</tr></thead>
      <tbody>${tbody}</tbody>
    </table>
    </body></html>`;

  const w = window.open('','_blank','width=1100,height=700');
  w.document.write(html);
  w.document.close();
  setTimeout(() => w.print(), 600);
};