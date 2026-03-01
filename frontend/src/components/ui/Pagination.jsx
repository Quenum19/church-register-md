/**
 * Pagination.jsx — composant réutilisable
 * Affiche toujours la pagination (même page 1/1), boutons désactivés si limite
 */
const Pagination = ({ page, pages, total, limit = 20, onPageChange }) => {
  const start = (page - 1) * limit + 1;
  const end   = Math.min(page * limit, total);

  // Calculer les pages à afficher (max 5 numéros)
  const getPageNumbers = () => {
    if (pages <= 5) return Array.from({ length: pages }, (_, i) => i + 1);
    if (page <= 3)  return [1, 2, 3, 4, 5];
    if (page >= pages - 2) return [pages-4, pages-3, pages-2, pages-1, pages];
    return [page-2, page-1, page, page+1, page+2];
  };

  return (
    <div className="flex flex-col sm:flex-row items-center justify-between gap-3 mt-2">
      {/* Infos résultats */}
      <p className="text-xs text-gray-400 font-body">
        {total === 0 ? 'Aucun résultat' : `Affichage ${start}–${end} sur ${total}`}
      </p>

      {/* Boutons pagination */}
      <div className="flex items-center gap-1.5">
        {/* Première page */}
        <button onClick={() => onPageChange(1)} disabled={page === 1}
          className="w-8 h-8 rounded-lg border-2 border-gray-200 text-xs font-bold text-gray-500
                     disabled:opacity-30 disabled:cursor-not-allowed hover:border-church-purple/40
                     hover:text-church-purple transition-all">
          «
        </button>
        {/* Page précédente */}
        <button onClick={() => onPageChange(page - 1)} disabled={page === 1}
          className="w-8 h-8 rounded-lg border-2 border-gray-200 text-sm font-bold text-gray-500
                     disabled:opacity-30 disabled:cursor-not-allowed hover:border-church-purple/40
                     hover:text-church-purple transition-all">
          ‹
        </button>

        {/* Numéros de page */}
        {getPageNumbers().map(p => (
          <button key={p} onClick={() => onPageChange(p)}
            className={`w-8 h-8 rounded-lg text-sm font-semibold transition-all font-body
              ${p === page
                ? 'bg-church-purple text-white shadow-sm border-2 border-church-purple'
                : 'border-2 border-gray-200 text-gray-600 hover:border-church-purple/40 hover:text-church-purple'}`}>
            {p}
          </button>
        ))}

        {/* Page suivante */}
        <button onClick={() => onPageChange(page + 1)} disabled={page === pages}
          className="w-8 h-8 rounded-lg border-2 border-gray-200 text-sm font-bold text-gray-500
                     disabled:opacity-30 disabled:cursor-not-allowed hover:border-church-purple/40
                     hover:text-church-purple transition-all">
          ›
        </button>
        {/* Dernière page */}
        <button onClick={() => onPageChange(pages)} disabled={page === pages}
          className="w-8 h-8 rounded-lg border-2 border-gray-200 text-xs font-bold text-gray-500
                     disabled:opacity-30 disabled:cursor-not-allowed hover:border-church-purple/40
                     hover:text-church-purple transition-all">
          »
        </button>
      </div>

      {/* Page X / Y */}
      <p className="text-xs text-gray-400 font-body">
        Page <strong className="text-gray-600">{page}</strong> / {pages}
      </p>
    </div>
  );
};

export default Pagination;