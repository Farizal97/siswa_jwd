document.addEventListener('DOMContentLoaded', function () {
  const searchInput = document.getElementById('searchInput');
  const jurusanFilter = document.getElementById('jurusanFilter');
  const rows = document.querySelectorAll('.student-row');

  function filterTable() {
    const keyword = searchInput.value.toLowerCase();
    const selectedJurusan = jurusanFilter.value;

    rows.forEach(row => {
      const text = row.innerText.toLowerCase();
      const rowJurusan = row.getAttribute('data-jurusan');
      const matchesText = text.includes(keyword);
      const matchesJurusan = selectedJurusan === 'ALL' || rowJurusan === selectedJurusan;

      row.style.display = (matchesText && matchesJurusan) ? '' : 'none';
    });
  }

  if (searchInput && jurusanFilter) {
    searchInput.addEventListener('input', filterTable);
    jurusanFilter.addEventListener('change', filterTable);
  }
});
