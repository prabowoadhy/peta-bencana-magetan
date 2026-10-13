/**
 * Peta Bencana Magetan — peta choropleth per desa.
 *
 * Sumber data: REST /wp-json/pbm/v1/rekap (kolom Excel).
 * Pengelompokan murni per desa; koordinat tidak dipakai untuk plotting.
 */
(function () {
	'use strict';

	if (typeof L === 'undefined') return;

	var W = window.PBM || {};
	var i18n = W.i18n || {};

	/* Kelas warna: 0, 1-4, 5-19, 20-99, >=100 kejadian */
	var BIN = [1, 5, 20, 100];
	var WARNA = ['#f7f7f7', '#fee5d9', '#fcae91', '#fb6a4a', '#a50f15'];

	var state = { dari: W.dari || '', sampai: W.sampai || '', bencana: W.bencana || '' };
	var map, desaLayer, kecLayer, rekap = {}, rekapJenis = {}, legend;

	function warna(n) {
		if (!n) return WARNA[0];
		for (var i = 0; i < BIN.length; i++) if (n < BIN[i]) return WARNA[i + 1];
		return WARNA[WARNA.length - 1];
	}

	function angka(v) { return Number(v || 0).toLocaleString('id-ID'); }

	function escapeHtml(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function api(path, params) {
		var url = new URL(W.rest + '/' + path);
		Object.keys(params || {}).forEach(function (k) {
			if (params[k]) url.searchParams.set(k, params[k]);
		});
		return fetch(url).then(function (r) { return r.json(); });
	}

	/* ---------------------------------------------------------- gaya */

	function styleDesa(f) {
		var r = rekap[f.properties.kode];
		var n = r ? r.kejadian : 0;
		return {
			color: n ? '#7f1015' : '#5a6b7d',
			weight: n ? 1.6 : 0.8,
			opacity: 0.95,
			fillColor: warna(n),
			fillOpacity: n ? 0.9 : 0.3
		};
	}

	/* --------------------------------------------------------- popup */

	function popupHtml(kode, props) {
		var r = rekap[kode];
		var baris = '';
		if (r) {
			var perJenis = rekapJenis[kode] || {};
			var kunci = Object.keys(perJenis).sort(function (a, b) { return perJenis[b] - perJenis[a]; });
			baris = '<tr><td>' + (i18n.kejadian || 'Total kejadian') + '</td><td><b>' + angka(r.kejadian) + '</b></td></tr>';
			if (kunci.length) {
				baris += '<tr class="pbm-popup-sep"><td colspan="2">' + (i18n.perJenis || 'Per jenis bencana') + '</td></tr>';
				kunci.forEach(function (j) {
					baris += '<tr><td>' + escapeHtml(j) + '</td><td><b>' + angka(perJenis[j]) + '</b></td></tr>';
				});
			}
		} else {
			baris = '<tr><td colspan="2" class="pbm-popup-kosong">' + (i18n.tanpaData || 'Belum ada laporan') + '</td></tr>';
		}
		return '<div class="pbm-popup">'
			+ '<h4>' + escapeHtml(props.nama) + '</h4>'
			+ '<div class="pbm-popup-sub">Desa, Kec. ' + escapeHtml(props.kecamatan) + ' · ' + escapeHtml(kode) + '</div>'
			+ '<table>' + baris + '</table></div>';
	}

	/* ------------------------------------------------------ statistik */

	function hitungStats() {
		var el = document.getElementById('pbm-stats');
		if (!el) return;

		var t = { kejadian: 0, desa: 0, kecamatan: {} };
		Object.keys(rekap).forEach(function (k) {
			var r = rekap[k];
			t.kejadian += r.kejadian;
			t.desa++;
			if (r.kecamatan) t.kecamatan[r.kecamatan] = 1;
		});

		el.innerHTML =
			kartu(t.kejadian, i18n.kejadian) +
			kartu(t.desa, i18n.desaTerdampak) +
			kartu(Object.keys(t.kecamatan).length, i18n.kecamatan);

		/* Akumulasi kabupaten per jenis bencana */
		var perJenis = {};
		Object.keys(rekapJenis).forEach(function (kode) {
			var j = rekapJenis[kode] || {};
			Object.keys(j).forEach(function (key) { perJenis[key] = (perJenis[key] || 0) + j[key]; });
		});
		var urut = Object.keys(perJenis).sort(function (a, b) { return perJenis[b] - perJenis[a]; });

		var lama = el.parentNode.querySelector('.pbm-jenis-box');
		if (lama) lama.parentNode.removeChild(lama);
		if (urut.length) {
			var html = '<div class="pbm-jenis-box"><b>' + (i18n.jenis || 'Kejadian per jenis bencana') + '</b><ul>';
			urut.forEach(function (key) {
				html += '<li><span>' + escapeHtml(key) + '</span><b>' + angka(perJenis[key]) + '</b></li>';
			});
			html += '</ul></div>';
			el.insertAdjacentHTML('afterend', html);
		}
	}

	function kartu(n, label) {
		return '<div class="pbm-stat"><b>' + angka(n) + '</b><span>' + label + '</span></div>';
	}

	/* -------------------------------------------------------- legenda */

	function buatLegenda() {
		if (!legend) {
			legend = L.control({ position: 'bottomright' });
			legend.onAdd = function () {
				var div = L.DomUtil.create('div', 'pbm-leaflet-legend');
				function item(c, t) {
					return '<span class="kotak" style="background:' + c + '"></span>' + t + '<br>';
				}
				div.innerHTML = '<b>Jumlah kejadian</b>'
					+ item(WARNA[0], '0') + item(WARNA[1], '1–4') + item(WARNA[2], '5–19')
					+ item(WARNA[3], '20–99') + item(WARNA[4], '≥ 100')
					+ '<div class="pbm-legenda-titik"><span class="garis"></span>Batas kecamatan</div>';
				return div;
			};
		}
		legend.addTo(map);
	}

	/* ----------------------------------------------------------- data */

	function muat() {
		return api('rekap', { dari: state.dari, sampai: state.sampai, bencana: state.bencana })
			.then(function (d) {
				rekap = (d && d.desa) || {};
				rekapJenis = (d && d.jenis) || {};
				if (desaLayer) desaLayer.setStyle(styleDesa);
				hitungStats();

				var rk = document.getElementById('pbm-rekap');
				if (rk) {
					var n = Object.keys(rekap).length;
					var periode = (state.dari || state.sampai)
						? (state.dari || '…') + ' s.d. ' + (state.sampai || '…')
						: 'seluruh periode';
					rk.innerHTML = '<p>' + (n
						? n + ' desa punya laporan pada ' + periode
							+ (state.bencana ? ' (jenis: ' + escapeHtml(state.bencana) + ')' : '')
							+ '. Klik desa di peta untuk rincian per jenis bencana.'
						: (i18n.kosong || 'Tidak ada laporan pada periode ini.')) + '</p>';
				}
			})
			.catch(function () {
				var el = document.getElementById('pbm-stats') || document.getElementById('pbm-rekap');
				if (el) el.innerHTML = '<p class="pbm-error">' + (i18n.gagal || 'Gagal memuat data.') + '</p>';
			});
	}

	/* ----------------------------------------------------------- peta */

	function initMap() {
		var el = document.getElementById('pbm-map');
		if (!el) return;

		map = L.map(el, { scrollWheelZoom: false, minZoom: 9 });
		map.setView([-7.6535, 111.3473], 11);

		L.tileLayer(
			'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Base/MapServer/tile/{z}/{y}/{x}',
			{ attribution: 'Tiles © Esri — Sumber: PDSI - Pusdatinkom BNPB', maxZoom: 17 }
		).addTo(map);

		var info = L.control({ position: 'topleft' });
		info.onAdd = function () {
			var div = L.DomUtil.create('div', 'pbm-info');
			div.id = 'pbm-info-inner';
			div.innerHTML = '<b>Kabupaten Magetan</b><br><span>' + (i18n.muat || 'Memuat…') + '</span>';
			L.DomEvent.disableClickPropagation(div);
			return div;
		};
		info.addTo(map);

		Promise.all([
			fetch(W.desaUrl).then(function (r) { return r.json(); }),
			fetch(W.kecUrl).then(function (r) { return r.json(); })
		]).then(function (arr) {
			desaLayer = L.geoJSON(arr[0], {
				style: styleDesa,
				onEachFeature: function (f, layer) {
					layer.bindPopup(function () { return popupHtml(f.properties.kode, f.properties); });
					layer.on('mouseover', function () { layer.setStyle({ weight: 2.5, color: '#1d3557' }); });
					layer.on('mouseout', function () { desaLayer.resetStyle(layer); });
				}
			}).addTo(map);

			kecLayer = L.geoJSON(arr[1], {
				interactive: false,
				style: { color: '#33475b', weight: 2, fill: false, dashArray: '4 3' }
			}).addTo(map);

			map.fitBounds(desaLayer.getBounds(), { padding: [8, 8] });
			buatLegenda();

			var infoEl = document.getElementById('pbm-info-inner');
			if (infoEl) {
				infoEl.innerHTML = '<b>Kabupaten Magetan</b><br><span>' + (i18n.info || '') + '</span>';
			}
			var rk = document.getElementById('pbm-rekap');
			if (rk) rk.innerHTML = '<p>Memuat data…</p>';

			return muat();
		}).catch(function () {
			var d = document.getElementById('pbm-info-inner');
			if (d) d.innerHTML = '<b>Kabupaten Magetan</b><br><span class="pbm-error">' + (i18n.gagal || 'Gagal memuat.') + '</span>';
		});
	}

	/* -------------------------------------------------------- filter */

	function pasangFilter() {
		var terapkan = document.getElementById('pbm-terapkan');
		var reset = document.getElementById('pbm-reset');

		if (terapkan) {
			terapkan.addEventListener('click', function () {
				state.dari = (document.getElementById('pbm-dari') || {}).value || '';
				state.sampai = (document.getElementById('pbm-sampai') || {}).value || '';
				state.bencana = (document.getElementById('pbm-jenis') || {}).value || '';
				muat();
			});
		}
		if (reset) {
			reset.addEventListener('click', function () {
				['pbm-dari', 'pbm-sampai'].forEach(function (id) {
					var e = document.getElementById(id);
					if (e) e.value = '';
				});
				var j = document.getElementById('pbm-jenis');
				if (j) j.value = '';
				state = { dari: '', sampai: '', bencana: '' };
				muat();
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { initMap(); pasangFilter(); });
	} else {
		initMap();
		pasangFilter();
	}
})();