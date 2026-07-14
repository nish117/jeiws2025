// Bikram Sambat (Nepali) <-> Gregorian (AD) date conversion + a reusable
// picker widget that keeps a native AD date input and three BS
// year/month/day <select> elements in sync. Mirrors lib/NepaliDate.php —
// keep both in sync if the data table below ever changes.
const NEPALI_MONTHS = ['Baishakh', 'Jestha', 'Ashadh', 'Shrawan', 'Bhadra', 'Ashwin',
  'Kartik', 'Mangsir', 'Poush', 'Magh', 'Falgun', 'Chaitra'];
const NEPALI_MIN_YEAR = 1975;
const NEPALI_MAX_YEAR = 2099;
const NEPALI_EPOCH_START = Date.UTC(1918, 3, 13); // = 1975-01-01 BS

const NEPALI_DAYS = {
  1975: [31,31,32,32,31,30,30,29,30,29,30,30,365],
  1976: [31,32,31,32,31,30,30,30,29,29,30,31,366],
  1977: [30,32,31,32,31,31,29,30,30,29,29,31,365],
  1978: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  1979: [31,31,32,32,31,30,30,29,30,29,30,30,365],
  1980: [31,32,31,32,31,30,30,30,29,29,30,31,366],
  1981: [31,31,31,32,31,31,29,30,30,29,29,31,365],
  1982: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  1983: [31,31,32,32,31,30,30,29,30,29,30,30,365],
  1984: [31,32,31,32,31,30,30,30,29,29,30,31,366],
  1985: [31,31,31,32,31,31,29,30,30,29,30,30,365],
  1986: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  1987: [31,32,31,32,31,30,30,29,30,29,30,30,365],
  1988: [31,32,31,32,31,30,30,30,29,29,30,31,366],
  1989: [31,31,31,32,31,31,30,29,30,29,30,30,365],
  1990: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  1991: [31,32,31,32,31,30,30,30,29,29,30,30,365],
  1992: [31,32,31,32,31,30,30,30,29,30,29,31,366],
  1993: [31,31,31,32,31,31,30,29,30,29,30,30,365],
  1994: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  1995: [31,32,31,32,31,30,30,30,29,29,30,30,365],
  1996: [31,32,31,32,31,30,30,30,29,30,29,31,366],
  1997: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  1998: [31,31,32,31,32,30,30,29,30,29,30,30,365],
  1999: [31,32,31,32,31,30,30,30,29,29,30,31,366],
  2000: [30,32,31,32,31,30,30,30,29,30,29,31,365],
  2001: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2002: [31,31,32,32,31,30,30,29,30,29,30,30,365],
  2003: [31,32,31,32,31,30,30,30,29,29,30,31,366],
  2004: [30,32,31,32,31,30,30,30,29,30,29,31,365],
  2005: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2006: [31,31,32,32,31,30,30,29,30,29,30,30,365],
  2007: [31,32,31,32,31,30,30,30,29,29,30,31,366],
  2008: [31,31,31,32,31,31,29,30,30,29,29,31,365],
  2009: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2010: [31,31,32,32,31,30,30,29,30,29,30,30,365],
  2011: [31,32,31,32,31,30,30,30,29,29,30,31,366],
  2012: [31,31,31,32,31,31,29,30,30,29,30,30,365],
  2013: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2014: [31,31,32,32,31,30,30,29,30,29,30,30,365],
  2015: [31,32,31,32,31,30,30,30,29,29,30,31,366],
  2016: [31,31,31,32,31,31,29,30,30,29,30,30,365],
  2017: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2018: [31,32,31,32,31,30,30,29,30,29,30,30,365],
  2019: [31,32,31,32,31,30,30,30,29,30,29,31,366],
  2020: [31,31,31,32,31,31,30,29,30,29,30,30,365],
  2021: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2022: [31,32,31,32,31,30,30,30,29,29,30,30,365],
  2023: [31,32,31,32,31,30,30,30,29,30,29,31,366],
  2024: [31,31,31,32,31,31,30,29,30,29,30,30,365],
  2025: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2026: [31,32,31,32,31,30,30,30,29,29,30,31,366],
  2027: [30,32,31,32,31,30,30,30,29,30,29,31,365],
  2028: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2029: [31,31,32,31,32,30,30,29,30,29,30,30,365],
  2030: [31,32,31,32,31,30,30,30,29,29,30,31,366],
  2031: [30,32,31,32,31,30,30,30,29,30,29,31,365],
  2032: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2033: [31,31,32,32,31,30,30,29,30,29,30,30,365],
  2034: [31,32,31,32,31,30,30,30,29,29,30,31,366],
  2035: [30,32,31,32,31,31,29,30,30,29,29,31,365],
  2036: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2037: [31,31,32,32,31,30,30,29,30,29,30,30,365],
  2038: [31,32,31,32,31,30,30,30,29,29,30,31,366],
  2039: [31,31,31,32,31,31,29,30,30,29,30,30,365],
  2040: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2041: [31,31,32,32,31,30,30,29,30,29,30,30,365],
  2042: [31,32,31,32,31,30,30,30,29,29,30,31,366],
  2043: [31,31,31,32,31,31,29,30,30,29,30,30,365],
  2044: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2045: [31,32,31,32,31,30,30,29,30,29,30,30,365],
  2046: [31,32,31,32,31,30,30,30,29,29,30,31,366],
  2047: [31,31,31,32,31,31,30,29,30,29,30,30,365],
  2048: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2049: [31,32,31,32,31,30,30,30,29,29,30,30,365],
  2050: [31,32,31,32,31,30,30,30,29,30,29,31,366],
  2051: [31,31,31,32,31,31,30,29,30,29,30,30,365],
  2052: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2053: [31,32,31,32,31,30,30,30,29,29,30,30,365],
  2054: [31,32,31,32,31,30,30,30,29,30,29,31,366],
  2055: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2056: [31,31,32,31,32,30,30,29,30,29,30,30,365],
  2057: [31,32,31,32,31,30,30,30,29,29,30,31,366],
  2058: [30,32,31,32,31,30,30,30,29,30,29,31,365],
  2059: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2060: [31,31,32,32,31,30,30,29,30,29,30,30,365],
  2061: [31,32,31,32,31,30,30,30,29,29,30,31,366],
  2062: [30,32,31,32,31,31,29,30,29,30,29,31,365],
  2063: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2064: [31,31,32,32,31,30,30,29,30,29,30,30,365],
  2065: [31,32,31,32,31,30,30,30,29,29,30,31,366],
  2066: [31,31,31,32,31,31,29,30,30,29,29,31,365],
  2067: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2068: [31,31,32,32,31,30,30,29,30,29,30,30,365],
  2069: [31,32,31,32,31,30,30,30,29,29,30,31,366],
  2070: [31,31,31,32,31,31,29,30,30,29,30,30,365],
  2071: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2072: [31,32,31,32,31,30,30,29,30,29,30,30,365],
  2073: [31,32,31,32,31,30,30,30,29,29,30,31,366],
  2074: [31,31,31,32,31,31,30,29,30,29,30,30,365],
  2075: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2076: [31,32,31,32,31,30,30,30,29,29,30,30,365],
  2077: [31,32,31,32,31,30,30,30,29,30,29,31,366],
  2078: [31,31,31,32,31,31,30,29,30,29,30,30,365],
  2079: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2080: [31,32,31,32,31,30,30,30,29,29,30,30,365],
  2081: [31,32,31,32,31,30,30,30,29,30,29,31,366],
  2082: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2083: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2084: [31,32,31,32,31,30,30,30,29,29,30,31,366],
  2085: [30,32,31,32,31,30,30,30,29,30,29,31,365],
  2086: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2087: [31,31,32,32,31,30,30,29,30,29,30,30,365],
  2088: [31,32,31,32,31,30,30,30,29,29,30,31,366],
  2089: [30,32,31,32,31,30,30,30,29,30,29,31,365],
  2090: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2091: [31,31,32,32,31,30,30,29,30,29,30,30,365],
  2092: [31,32,31,32,31,30,30,30,29,29,30,31,366],
  2093: [31,31,31,32,31,31,29,30,30,29,29,31,365],
  2094: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2095: [31,31,32,32,31,30,30,29,30,29,30,30,365],
  2096: [31,32,31,32,31,30,30,30,29,29,30,31,366],
  2097: [31,31,31,32,31,31,29,30,30,29,30,30,365],
  2098: [31,31,32,31,31,31,30,29,30,29,30,30,365],
  2099: [31,31,32,32,31,30,30,29,30,29,30,30,365],
};

function nepDaysInMonth(year, month) {
  const row = NEPALI_DAYS[year];
  if (!row || month < 1 || month > 12) return null;
  return row[month - 1];
}

// 'YYYY-MM-DD' AD -> {year, month, day} BS, or null if out of the supported range.
function adToBs(adDateStr) {
  const parts = (adDateStr || '').split('-').map(Number);
  const [y, m, d] = parts;
  if (!y || !m || !d) return null;
  let remaining = Math.round((Date.UTC(y, m - 1, d) - NEPALI_EPOCH_START) / 86400000);
  if (remaining < 0) return null;

  for (let year = NEPALI_MIN_YEAR; year <= NEPALI_MAX_YEAR; year++) {
    const row = NEPALI_DAYS[year];
    if (remaining >= row[12]) { remaining -= row[12]; continue; }
    for (let month = 0; month < 12; month++) {
      if (remaining >= row[month]) { remaining -= row[month]; continue; }
      return { year, month: month + 1, day: remaining + 1 };
    }
  }
  return null;
}

// BS year/month/day -> 'YYYY-MM-DD' AD, or null if invalid/out of range.
function bsToAdStr(year, month, day) {
  const maxDay = nepDaysInMonth(year, month);
  if (maxDay === null || day < 1 || day > maxDay) return null;

  let totalDays = 0;
  for (let y = NEPALI_MIN_YEAR; y < year; y++) totalDays += NEPALI_DAYS[y][12];
  for (let m = 0; m < month - 1; m++) totalDays += NEPALI_DAYS[year][m];
  totalDays += day - 1;

  const dt = new Date(NEPALI_EPOCH_START + totalDays * 86400000);
  const yyyy = dt.getUTCFullYear();
  const mm = String(dt.getUTCMonth() + 1).padStart(2, '0');
  const dd = String(dt.getUTCDate()).padStart(2, '0');
  return `${yyyy}-${mm}-${dd}`;
}

function formatBsDisplay(bs) {
  return `${bs.year}-${String(bs.month).padStart(2, '0')}-${String(bs.day).padStart(2, '0')}`;
}

// Builds the popup-calendar DOM inside an empty wrapper element.
function buildNepaliCalendarDom(wrapper) {
  wrapper.classList.add('bs-datepicker-wrap');
  wrapper.innerHTML = `
    <input type="text" class="bs-display-input" readonly placeholder="Nepali date (B.S.)">
    <i class="fa-solid fa-calendar-days date-input-icon"></i>
    <div class="bs-calendar-popup">
      <div class="bs-cal-header">
        <button type="button" class="bs-cal-nav bs-cal-prev" tabindex="-1"><i class="fa-solid fa-chevron-left"></i></button>
        <select class="bs-cal-month"></select>
        <select class="bs-cal-year"></select>
        <button type="button" class="bs-cal-nav bs-cal-next" tabindex="-1"><i class="fa-solid fa-chevron-right"></i></button>
      </div>
      <div class="bs-cal-weekdays"><span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span></div>
      <div class="bs-cal-grid"></div>
    </div>`;
  return {
    display:  wrapper.querySelector('.bs-display-input'),
    popup:    wrapper.querySelector('.bs-calendar-popup'),
    monthSel: wrapper.querySelector('.bs-cal-month'),
    yearSel:  wrapper.querySelector('.bs-cal-year'),
    grid:     wrapper.querySelector('.bs-cal-grid'),
    prevBtn:  wrapper.querySelector('.bs-cal-prev'),
    nextBtn:  wrapper.querySelector('.bs-cal-next'),
  };
}

// Wires a native AD <input type=date> (+ optional paired display input) to a
// popup Nepali (B.S.) calendar rendered inside `wrapper` (an otherwise-empty
// container element), so picking a date in either one updates the other.
// `onChange(adStr)` fires after a user picks a BS date (e.g. to auto-submit
// a filter form).
function attachNepaliDatePicker({ adNative, adDisplay, wrapper, onChange }) {
  const els = buildNepaliCalendarDom(wrapper);
  els.monthSel.innerHTML = NEPALI_MONTHS.map((n, i) => `<option value="${i + 1}">${n}</option>`).join('');
  let yearOpts = '';
  for (let y = NEPALI_MIN_YEAR; y <= NEPALI_MAX_YEAR; y++) yearOpts += `<option value="${y}">${y}</option>`;
  els.yearSel.innerHTML = yearOpts;

  let view = { year: NEPALI_MIN_YEAR, month: 1 }; // month currently shown in the grid
  let selected = null;                            // {year, month, day} currently chosen

  function renderGrid() {
    const days = nepDaysInMonth(view.year, view.month);
    if (!days) { els.grid.innerHTML = ''; return; }
    const firstAd = bsToAdStr(view.year, view.month, 1);
    const firstDow = firstAd ? new Date(firstAd + 'T00:00:00Z').getUTCDay() : 0;
    let html = '';
    for (let i = 0; i < firstDow; i++) html += '<span class="bs-cal-empty"></span>';
    for (let d = 1; d <= days; d++) {
      const isSel = selected && selected.year === view.year && selected.month === view.month && selected.day === d;
      html += `<button type="button" class="bs-cal-day${isSel ? ' is-selected' : ''}" data-day="${d}">${d}</button>`;
    }
    els.grid.innerHTML = html;
  }

  function renderHeader() {
    els.monthSel.value = view.month;
    els.yearSel.value = view.year;
  }

  function onOutsideClick(e) {
    if (!wrapper.contains(e.target)) closePopup();
  }
  function openPopup() {
    if (selected) view = { year: selected.year, month: selected.month };
    renderHeader();
    renderGrid();
    els.popup.classList.add('is-open');
    document.addEventListener('click', onOutsideClick, true);
  }
  function closePopup() {
    els.popup.classList.remove('is-open');
    document.removeEventListener('click', onOutsideClick, true);
  }

  els.display.addEventListener('click', () => {
    els.popup.classList.contains('is-open') ? closePopup() : openPopup();
  });
  els.prevBtn.addEventListener('click', () => {
    view.month--; if (view.month < 1) { view.month = 12; view.year--; }
    renderHeader(); renderGrid();
  });
  els.nextBtn.addEventListener('click', () => {
    view.month++; if (view.month > 12) { view.month = 1; view.year++; }
    renderHeader(); renderGrid();
  });
  els.monthSel.addEventListener('change', () => { view.month = parseInt(els.monthSel.value, 10); renderGrid(); });
  els.yearSel.addEventListener('change', () => { view.year = parseInt(els.yearSel.value, 10); renderGrid(); });
  els.grid.addEventListener('click', (e) => {
    const btn = e.target.closest('.bs-cal-day');
    if (!btn) return;
    selectBsDate(view.year, view.month, parseInt(btn.dataset.day, 10));
    closePopup();
  });

  function selectBsDate(year, month, day) {
    const adStr = bsToAdStr(year, month, day);
    if (!adStr) return;
    selected = { year, month, day };
    els.display.value = formatBsDisplay(selected);
    adNative.value = adStr;
    if (adDisplay) adDisplay.value = adStr;
    if (onChange) onChange(adStr);
  }

  function setFromAdValue(adStr) {
    const bs = adToBs(adStr);
    if (!bs) return;
    selected = bs;
    view = { year: bs.year, month: bs.month };
    els.display.value = formatBsDisplay(bs);
  }

  adNative.addEventListener('change', () => {
    if (adDisplay) adDisplay.value = adNative.value;
    setFromAdValue(adNative.value);
  });

  if (adNative.value) setFromAdValue(adNative.value);

  return { setFromAdValue };
}