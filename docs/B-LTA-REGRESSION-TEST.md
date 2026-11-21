# PAdES B-LTA Regression Test

## Purpose

Verify that PiPDFBox creates a valid PAdES Baseline B-LTA signature
using the D-TRUST signing certificate and the BaltStamp qualified TSA.

## Reference test

- Test date: 2026-11-21
- Application: PiPDFBox
- Signing certificate: D-TRUST CA 3-21-1 2021
- TSA: BaltStamp QTSA TSU1
- TSA endpoint: http://tsa2.baltstamp.lt
- DSS validator: EU Digital Signature Services 6.5.1
- Fix commit: 63a58cf

## Test procedure

1. Select a test PDF in PiPDFBox.
2. Enable B-LTA signing.
3. Sign the PDF using the D-TRUST card.
4. Validate the resulting PDF with the EU DSS validator.
5. Inspect the detailed validation report.

## Expected results

The DSS validation report must show:

- Signature Format: PAdES-BASELINE-LTA
- Qualification level: QESig
- Indication: TOTAL_PASSED
- Document signature: valid
- At least one qualified timestamp with indication PASSED
- Timestamp certificate chain:
  - BalTstamp QTSA TSU1
  - BaltStamp Issuing CA 2026
  - BaltStamp Root CA 2026

## Reference test result

Status: PASSED

Observed on 2026-11-21:

- Signature Format: PAdES-BASELINE-LTA
- Qualification level: QESig
- Indication: TOTAL_PASSED
- Valid signatures: 1 out of 1
- Qualified timestamps: 2
- Both timestamps: PASSED
- DSS validation: successful

## Regression acceptance criteria

A new release passes this manual regression test when:

1. PiPDFBox successfully creates a B-LTA signed PDF.
2. The independent DSS validator recognizes PAdES-BASELINE-LTA.
3. The signature indication is TOTAL_PASSED.
4. The qualified timestamp validation succeeds.
5. The BaltStamp certificate chain is accepted.

## Notes

The reference PDF and DSS report are not stored in the repository
because they may contain personal or document-specific information.

This is a documented manual acceptance test, not an automated test.
