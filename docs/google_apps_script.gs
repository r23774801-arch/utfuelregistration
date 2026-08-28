const SECRET_TOKEN = 'ut-fuel-monitoring';
const SHEET_NAME = 'Fuel Registration';
const HEADER_ROW = [
  'Kode',
  'Tanggal Input',
  'Operator',
  'Type Unit',
  'Nomor Polisi',
  'No Lambung',
  'Area',
  'Meteran Besar Flow Meter',
  'Control',
  'Meteran Kecil Flow Meter',
  'Liter Pengisian',
  'HM/KM Kendaraan',
  'Selisih / Liter Pengisian',
  'Dokumentasi Pengisian',
  'Foto Flow Meter'
];

function doPost(e) {
  try {
    const payload = JSON.parse(e.postData.contents || '{}');

    if (payload.secret !== SECRET_TOKEN) {
      return jsonResponse({ ok: false, error: 'Unauthorized' });
    }

    const spreadsheet = SpreadsheetApp.getActiveSpreadsheet();
    let sheet = spreadsheet.getSheetByName(SHEET_NAME);

    if (!sheet) {
      sheet = spreadsheet.insertSheet(SHEET_NAME);
    }

    sheet.getRange(1, 1, 1, HEADER_ROW.length).setValues([HEADER_ROW]);

    sheet.appendRow([
      payload.code || '',
      payload.fuel_date || '',
      payload.operator_name || '',
      payload.type_unit || '',
      payload.nomor_polisi || '',
      payload.no_lambung || '',
      payload.area || '',
      payload.ltr_besar || '',
      payload.control || payload.ltr_kecil || '',
      payload.ltr_kecil || '',
      payload.total_liters || '',
      payload.hm_awal || '',
      payload.total_usage || '',
      payload.foto_form_url || '',
      payload.foto_km_url || ''
    ]);

    return jsonResponse({ ok: true });
  } catch (err) {
    return jsonResponse({ ok: false, error: String(err) });
  }
}

function jsonResponse(data) {
  return ContentService
    .createTextOutput(JSON.stringify(data))
    .setMimeType(ContentService.MimeType.JSON);
}
