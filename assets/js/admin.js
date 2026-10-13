/** Admin: dropdown Desa mengikuti Kecamatan + konfirmasi hapus. */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var selKec = document.getElementById('pbm-f-kec');
		var selDesa = document.getElementById('pbm-f-desa');

		if (selKec && selDesa && window.PBM_DESA) {
			var semua = window.PBM_DESA;
			selKec.addEventListener('change', function () {
				var kec = selKec.value;
				selDesa.innerHTML = '';
				var opt0 = document.createElement('option');
				opt0.value = '';
				opt0.textContent = kec ? '— pilih desa —' : '— pilih kecamatan dulu —';
				selDesa.appendChild(opt0);

				semua
					.filter(function (d) { return d.kecamatan === kec; })
					.sort(function (a, b) { return a.nama.localeCompare(b.nama); })
					.forEach(function (d) {
						var o = document.createElement('option');
						o.value = d.kode;
						o.textContent = d.nama;
						selDesa.appendChild(o);
					});
			});
		}

		document.querySelectorAll('a.pbm-hapus').forEach(function (a) {
			a.addEventListener('click', function (e) {
				if (!confirm('Hapus data ini?')) e.preventDefault();
			});
		});
	});
})();