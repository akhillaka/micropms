/**
 * Google Apps Script for Hotel PMS Google Sheets Synchronization
 * 
 * Instructions:
 * 1. Open your Google Spreadsheet.
 * 2. Click Extensions > Apps Script.
 * 3. Delete existing code and paste this entire file into Code.gs.
 * 4. Click Deploy > New deployment.
 * 5. Select type: "Web app".
 * 6. Execute as: "Me".
 * 7. Who has access: "Anyone".
 * 8. Click Deploy, authorize access, and copy the Web App URL.
 * 9. Paste the Web App URL into your Hotel PMS Settings (Google Sheets Webhook URL).
 */

const HEADERS = {
  booking: [
    "Booking ID", "Folio No", "Room No", "Room Type", "Full Name", "Phone No", 
    "Rate per night", "Month", "Check-in Date", "Check-In TIme", "Check-Out-Date", 
    "Check-Out Time", "Duration in days", "Duration in hrs", "Total Amount Collected", 
    "Check-in/Check-Out", "user"
  ],
  payment: [
    "Booking ID", "Folio No", "Room No", "Room Type", "Full Name", 
    "Amount Paid", "Payment Type", "Month", "Payment Date", "Category", "user"
  ],
  expense: [
    "Expense ID", "Category", "Amount", "Description", 
    "Payment Method", "Month", "Expense Date", "User"
  ]
};

const SHEET_NAMES = {
  booking: "Bookings",
  payment: "Payments",
  expense: "Expenses"
};

function doPost(e) {
  try {
    const rawData = e.postData.contents;
    const payload = JSON.parse(rawData);
    
    if (payload.action === "ping") {
      return jsonResponse({ status: "success", message: "Google Sheets Webhook active and reachable." });
    }
    
    if (payload.action === "sync_row") {
      const result = handleSyncRow(payload.sheet_type, payload.data);
      return jsonResponse({ status: "success", result: result });
    }
    
    if (payload.action === "bulk_sync") {
      const results = [];
      if (Array.isArray(payload.items)) {
        payload.items.forEach(item => {
          results.push(handleSyncRow(item.sheet_type, item.data));
        });
      }
      return jsonResponse({ status: "success", count: results.length });
    }
    
    return jsonResponse({ status: "error", message: "Invalid action specified." }, 400);
  } catch (err) {
    return jsonResponse({ status: "error", message: err.toString() }, 500);
  }
}

function handleSyncRow(sheetType, rowData) {
  if (!SHEET_NAMES[sheetType]) {
    throw new Error("Invalid sheet type: " + sheetType);
  }
  
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const sheetName = SHEET_NAMES[sheetType];
  let sheet = ss.getSheetByName(sheetName);
  
  // Create sheet & headers if not exists
  if (!sheet) {
    sheet = ss.insertSheet(sheetName);
    sheet.appendRow(HEADERS[sheetType]);
    formatHeaderRow(sheet);
  } else {
    // Ensure header row is present
    if (sheet.getLastRow() === 0) {
      sheet.appendRow(HEADERS[sheetType]);
      formatHeaderRow(sheet);
    }
  }
  
  const expectedHeaders = HEADERS[sheetType];
  const rowValues = expectedHeaders.map(h => rowData[h] !== undefined && rowData[h] !== null ? rowData[h] : "");
  
  // Primary key check (Col 1: Booking ID or Expense ID)
  const primaryId = String(rowValues[0]);
  const data = sheet.getDataRange().getValues();
  let existingRowIndex = -1;
  
  for (let i = 1; i < data.length; i++) {
    if (String(data[i][0]) === primaryId) {
      // For payment sheet, match both Booking ID (Col 0) and Payment Date (Col 8) or Folio Ref if available
      if (sheetType === "payment") {
        const paymentDate = String(rowValues[8]);
        const amountPaid = String(rowValues[5]);
        if (String(data[i][8]) === paymentDate && String(data[i][5]) === amountPaid) {
          existingRowIndex = i + 1;
          break;
        }
      } else {
        existingRowIndex = i + 1;
        break;
      }
    }
  }
  
  if (existingRowIndex > 0) {
    // Update existing row
    const range = sheet.getRange(existingRowIndex, 1, 1, rowValues.length);
    range.setValues([rowValues]);
    return "updated_row_" + existingRowIndex;
  } else {
    // Append new row
    sheet.appendRow(rowValues);
    return "appended_new_row";
  }
}

function formatHeaderRow(sheet) {
  const headerRange = sheet.getRange(1, 1, 1, sheet.getLastColumn() || 1);
  headerRange.setFontWeight("bold");
  headerRange.setBackground("#4F46E5");
  headerRange.setFontColor("#FFFFFF");
}

function jsonResponse(data, statusCode) {
  return ContentService.createTextOutput(JSON.stringify(data))
    .setMimeType(ContentService.MimeType.JSON);
}
