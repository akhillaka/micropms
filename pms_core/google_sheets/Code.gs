/**
 * MicroPMS → Google Sheets webhook.
 *
 * Setup:
 * 1. Extensions → Apps Script → paste this file as Code.gs
 * 2. Deploy → New deployment → Web app
 *    - Execute as: Me
 *    - Who has access: Anyone
 * 3. Copy the /exec URL into MicroPMS Settings → Integrations
 *
 * Tabs created automatically: Bookings, Payments, Expenses
 * Rows upsert by Booking ID / Expense ID when that column exists.
 */

function doPost(e) {
  try {
    var payload = {};
    if (e && e.postData && e.postData.contents) {
      payload = JSON.parse(e.postData.contents);
    }
    var action = payload.action || '';
    if (action === 'ping') {
      return jsonOut({ status: 'success', message: 'Successfully connected to Google Sheets!' });
    }
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    if (action === 'sync_row') {
      upsertRow(ss, payload.sheet_type, payload.data || {});
      return jsonOut({ status: 'success' });
    }
    if (action === 'bulk_sync') {
      var items = payload.items || [];
      for (var i = 0; i < items.length; i++) {
        upsertRow(ss, items[i].sheet_type, items[i].data || {});
      }
      return jsonOut({ status: 'success', count: items.length });
    }
    return jsonOut({ status: 'error', message: 'Unknown action' });
  } catch (err) {
    return jsonOut({ status: 'error', message: String(err) });
  }
}

function jsonOut(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}

function sheetNameForType(type) {
  if (type === 'payment') return 'Payments';
  if (type === 'expense') return 'Expenses';
  return 'Bookings';
}

function upsertKey(type) {
  if (type === 'expense') return 'Expense ID';
  if (type === 'payment') return 'Payment ID';
  return 'Booking ID';
}

function upsertRow(ss, type, data) {
  var skip = { property_id: true };
  var keys = [];
  for (var k in data) {
    if (Object.prototype.hasOwnProperty.call(data, k) && !skip[k]) {
      keys.push(k);
    }
  }
  if (keys.length === 0) return;

  var name = sheetNameForType(type);
  var sheet = ss.getSheetByName(name);
  if (!sheet) {
    sheet = ss.insertSheet(name);
  }

  var lastCol = Math.max(sheet.getLastColumn(), 1);
  var lastRow = sheet.getLastRow();
  var headers = [];
  if (lastRow >= 1 && lastCol >= 1) {
    headers = sheet.getRange(1, 1, 1, lastCol).getValues()[0];
    headers = headers.map(function (h) { return String(h || '').trim(); });
    while (headers.length && headers[headers.length - 1] === '') {
      headers.pop();
    }
  }
  if (headers.length === 0) {
    headers = keys.slice();
    sheet.getRange(1, 1, 1, headers.length).setValues([headers]);
    sheet.getRange(1, 1, 1, headers.length).setFontWeight('bold');
  } else {
    var added = false;
    for (var i = 0; i < keys.length; i++) {
      if (headers.indexOf(keys[i]) === -1) {
        headers.push(keys[i]);
        added = true;
      }
    }
    if (added) {
      sheet.getRange(1, 1, 1, headers.length).setValues([headers]);
    }
  }

  var keyName = upsertKey(type);
  var keyVal = data[keyName] != null ? String(data[keyName]) : '';
  var targetRow = 0;
  if (keyVal && headers.indexOf(keyName) !== -1) {
    var keyCol = headers.indexOf(keyName) + 1;
    var values = sheet.getRange(2, keyCol, Math.max(sheet.getLastRow() - 1, 1), 1).getValues();
    for (var r = 0; r < values.length; r++) {
      if (String(values[r][0]) === keyVal) {
        targetRow = r + 2;
        break;
      }
    }
  }

  var rowVals = headers.map(function (h) {
    return data[h] != null ? data[h] : '';
  });
  if (targetRow > 0) {
    sheet.getRange(targetRow, 1, 1, headers.length).setValues([rowVals]);
  } else {
    sheet.appendRow(rowVals);
  }
}
