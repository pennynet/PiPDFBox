const picker=document.getElementById('pdfs');
const list=document.getElementById('filelist');
function showFiles(){const files=[...(picker.files||[])];list.textContent=files.length?files.map(f=>`${f.name} · ${(f.size/1024/1024).toFixed(2)} MB`).join('\n'):'Noch keine Dateien ausgewählt';}
picker.addEventListener('change',showFiles);
const drop=document.querySelector('.drop');
for(const ev of ['dragenter','dragover']) drop.addEventListener(ev,e=>{e.preventDefault();drop.classList.add('hover')});
for(const ev of ['dragleave','drop']) drop.addEventListener(ev,e=>{e.preventDefault();drop.classList.remove('hover')});
drop.addEventListener('drop',e=>{const dt=new DataTransfer();[...e.dataTransfer.files].filter(f=>f.type==='application/pdf'||f.name.toLowerCase().endsWith('.pdf')).forEach(f=>dt.items.add(f));picker.files=dt.files;showFiles()});
document.querySelector('form.panel').addEventListener('submit', (event) => {
    const b = event.submitter || document.activeElement;
    const result = document.querySelector('.result');

    if (result) {
        result.style.display = 'none';
        result.remove();
    }

    if (b && b.classList.contains('task-button')) {
        const action = document.createElement('input');
        action.type = 'hidden';
        action.name = 'action';
        action.value = b.value;
        b.form.appendChild(action);

        const success = document.querySelector('.success');
        if (success) {
            success.textContent = 'Verarbeitung läuft …';
        }

        b.disabled = true;
        b.textContent = 'Verarbeitung läuft …';
    }
});


const visibleStampCheckbox = document.getElementById('visible_stamp');
const tsaLinkWrap = document.getElementById('tsa-link-wrap');

if (visibleStampCheckbox && tsaLinkWrap) {
    const updateTsaLinkVisibility = () => {
        tsaLinkWrap.hidden = !visibleStampCheckbox.checked;
    };
    visibleStampCheckbox.addEventListener('change', updateTsaLinkVisibility);
    updateTsaLinkVisibility();
}

const settingsLink = document.getElementById('settings-link');
const settingsMenu = document.getElementById('settings-menu-dialog');
const sealiumDialog = document.getElementById('sealium-dialog');
const sealiumForm = document.getElementById('sealium-settings-form');
const sealiumKey = document.getElementById('sealium-api-key');
const sealiumStatus = document.getElementById('sealium-key-status');
const sealiumMessage = document.getElementById('sealium-settings-message');
const sealiumSaveButton = document.getElementById('sealium-save-button');

async function loadSealiumStatus() {
    try {
        const response = await fetch('/sealium-settings.php', {credentials:'same-origin', cache:'no-store'});
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'Status konnte nicht geladen werden.');
        sealiumStatus.textContent = data.configured ? 'API-Schlüssel ist gespeichert.' : 'Noch kein API-Schlüssel hinterlegt.';
    } catch (error) {
        sealiumStatus.textContent = error.message || 'Status nicht verfügbar.';
    }
}

if (settingsLink && settingsMenu) {
    settingsLink.addEventListener('click', event => {
        event.preventDefault();
        settingsMenu.showModal();
    });
    document.getElementById('settings-menu-close')?.addEventListener('click', () => settingsMenu.close());
    settingsMenu.addEventListener('click', event => {
        if (event.target === settingsMenu) settingsMenu.close();
    });
    document.getElementById('open-tsa-settings')?.addEventListener('click', () => {
        settingsMenu.close();
        tsaDialog?.showModal();
    });
    document.getElementById('open-sealium-settings')?.addEventListener('click', () => {
        settingsMenu.close();
        sealiumDialog?.showModal();
        sealiumKey.value = '';
        sealiumMessage.textContent = '';
        loadSealiumStatus();
    });
}

if (sealiumForm && sealiumDialog) {
    document.getElementById('sealium-dialog-cancel')?.addEventListener('click', () => sealiumDialog.close());
    sealiumDialog.addEventListener('click', event => {
        if (event.target === sealiumDialog) sealiumDialog.close();
    });
    sealiumForm.addEventListener('submit', async event => {
        event.preventDefault();
        const key = sealiumKey.value.trim();
        if (!key) {
            sealiumMessage.textContent = 'Bitte einen API-Schlüssel eingeben.';
            return;
        }
        sealiumSaveButton.disabled = true;
        sealiumSaveButton.textContent = 'Wird gespeichert …';
        sealiumMessage.textContent = '';
        try {
            const body = new FormData();
            body.append('api_key', key);
            body.append('csrf', document.querySelector('input[name="csrf"]')?.value || '');
            const response = await fetch('/sealium-settings.php', {method:'POST', body, credentials:'same-origin'});
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Schlüssel konnte nicht gespeichert werden.');
            sealiumKey.value = '';
            sealiumStatus.textContent = 'API-Schlüssel ist gespeichert.';
            sealiumMessage.textContent = 'Schlüssel sicher gespeichert. Die Verbindung wird bei der nächsten Signaturprüfung verwendet.';
        } catch (error) {
            sealiumMessage.textContent = error.message || 'Speichern fehlgeschlagen.';
        } finally {
            sealiumSaveButton.disabled = false;
            sealiumSaveButton.textContent = 'Speichern';
        }
    });
}

const tsaLink = document.getElementById('tsa-settings-link');
const tsaDialog = document.getElementById('tsa-dialog');
const tsaSelect = document.getElementById('tsa_server');
const tsaNewFields = document.getElementById('tsa-new-fields');
const tsaCancel = document.getElementById('tsa-dialog-cancel');

if (tsaLink && tsaDialog && tsaSelect) {
    tsaLink.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        tsaDialog.showModal();
    });
    tsaCancel.addEventListener('click', () => tsaDialog.close());
    tsaSelect.addEventListener('change', () => {
        tsaNewFields.hidden = tsaSelect.value !== '__new__';
    });
    tsaDialog.addEventListener('click', (event) => {
        if (event.target === tsaDialog) tsaDialog.close();
    });
}


// Signaturprüfung der bereits im Haupt-Uploadfeld ausgewählten PDF
const signatureLink = document.getElementById('signature-check-link');
const signatureResult = document.getElementById('signature-result');
const signatureDialog = document.getElementById('signature-dialog');
const signatureBack = document.getElementById('signature-back');
const signaturePrint = document.getElementById('signature-print');
const pdfPicker = document.getElementById('pdfs');

function showSignatureMessage(message, isError = false) {
    signatureResult.replaceChildren();
    const paragraph = document.createElement('p');
    paragraph.className = isError ? 'signature-error' : '';
    paragraph.textContent = message;
    signatureResult.appendChild(paragraph);
}

async function validateSelectedPdf() {
    const files = [...(pdfPicker.files || [])];
    if (files.length !== 1) {
        signatureDialog.showModal();
        showSignatureMessage(files.length === 0
            ? 'Bitte zuerst eine PDF in das große Hochladefeld ziehen oder dort auswählen.'
            : 'Für die Signaturprüfung bitte genau eine PDF im Hochladefeld auswählen.', true);
        return;
    }
    const file = files[0];
    signatureDialog.showModal();
    showSignatureMessage('Signatur wird bei Sealium geprüft …');
    const form = new FormData();
    form.append('pdf', file);
    form.append('csrf', document.querySelector('input[name="csrf"]')?.value || '');
    try {
        const response = await fetch('/validate-signature.php', {method:'POST', body:form, credentials:'same-origin'});
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'Prüfung fehlgeschlagen.');
        const signature = (data.signatures || [])[0] || {};
        const summary = data.validationSummary || {};
        // Bei gültigem qualifiziertem Zeitstempel den belegten Zeitpunkt anzeigen.
        // Andernfalls den vom Signierenden in der Signatur angegebenen Zeitpunkt
        // ausdrücklich als solchen kennzeichnen.
        const signingTime = signature.timestampValid === true
            ? (signature.bestSignatureTime || signature.signingTime || signature.claimedSigningTime)
            : (signature.claimedSigningTime || signature.signingTime);
        const formatDateTime = value => {
            if (!value) return 'Nicht angegeben';
            const date = new Date(value);
            if (Number.isNaN(date.getTime())) return String(value);
            const parts = new Intl.DateTimeFormat('de-DE', {
                timeZone: 'Europe/Berlin',
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hourCycle: 'h23'
            }).formatToParts(date);
            const part = type => parts.find(item => item.type === type)?.value || '';
            const zoneParts = new Intl.DateTimeFormat('de-DE', {
                timeZone: 'Europe/Berlin',
                timeZoneName: 'short'
            }).formatToParts(date);
            let zone = zoneParts.find(item => item.type === 'timeZoneName')?.value || '';
            if (zone === 'GMT+1' || zone === 'UTC+1') zone = 'MEZ';
            else if (zone === 'GMT+2' || zone === 'UTC+2') zone = 'MESZ';
            // ISO-ähnliches Format, passend für alle Zeitangaben einschließlich Prüfzeitpunkt.
            return `${part('year')}-${part('month')}-${part('day')} ${part('hour')}:${part('minute')}:${part('second')} ${zone}`.trim();
        };
        const signingTimeLabel = signature.timestampValid === true
            ? 'Signierzeitpunkt (zeitgestempelt)'
            : 'Signierzeitpunkt (laut Signatur)';
        // productionTime ist der im RFC-3161-Zeitstempel enthaltene Zeitpunkt.
        // Nur bei von Sealium bestätigtem Zeitstempel als vertrauenswürdig ausgeben.
        const timestampEntries = Array.isArray(signature.timestamps) ? signature.timestamps : [];
        const timestampProductionTimes = timestampEntries
            .filter(item => item && item.productionTime)
            .map(item => item.productionTime);
        const timestampApplicationTime = signature.timestampValid === true
            ? (timestampProductionTimes[0] || null)
            : null;
        const formatFileSize = bytes => {
            if (!Number.isFinite(bytes) || bytes < 0) return '';
            if (bytes < 1024) return bytes + ' B';
            const units = ['KB', 'MB', 'GB'];
            let size = bytes / 1024;
            let unit = units[0];
            for (let i = 1; size >= 1024 && i < units.length; i++) {
                size /= 1024;
                unit = units[i];
            }
            return size.toLocaleString('de-DE', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ' + unit;
        };
        const heading = document.getElementById('signature-dialog-title');
        if (heading) heading.textContent = 'Signaturprüfung';
        const rows = [
            ['Datei', file.name],
            ['Dateigröße', formatFileSize(file.size)],
            ['Gesamtergebnis', data.valid === true ? 'Gültig' : 'Nicht gültig'],
            ['Dokument unverändert', data.documentIntact === true ? 'Ja' : 'Nein'],
            ['Unterzeichner', signature.signerName || signature.signerDn || 'Nicht angegeben'],
            ['Signaturstufe', signature.signatureLevel || 'Nicht angegeben'],
            ['Signatur intakt', signature.signatureIntact === true ? 'Ja' : 'Nein'],
            ['Zertifikat gültig', signature.certValid === true ? 'Ja' : 'Nein'],
            ['Widerrufsprüfung', signature.revocationOk === true ? 'OK' : 'Nicht bestätigt'],
            [signingTimeLabel, formatDateTime(signingTime)],
            ['Zeitstempel der Signaturanbringung', timestampApplicationTime
                ? formatDateTime(timestampApplicationTime)
                : (signature.timestampValid === true ? 'Zeitpunkt nicht angegeben' : 'Kein gültiger Zeitstempel bestätigt')],
            ['Zeitstempel', signature.timestampValid === true ? 'Gültig' : 'Nicht bestätigt'],
            ['Qualifizierte Signatur', summary.hasQualifiedSignature === true ? 'Ja' : 'Nein'],
            ['Prüfzeitpunkt', formatDateTime(data.validatedAt)]
        ];
        signatureResult.replaceChildren();
        const status = document.createElement('p');
        status.className = 'signature-status ' + (data.valid === true ? 'good' : 'bad');
        status.textContent = data.valid === true ? 'Signaturprüfung erfolgreich' : 'Signatur nicht vollständig gültig';
        signatureResult.appendChild(status);
        const table = document.createElement('dl');
        rows.forEach(([label,value]) => {
            const term = document.createElement('dt'); term.textContent = label;
            const detail = document.createElement('dd'); detail.textContent = String(value);
            table.append(term,detail);
        });
        signatureResult.appendChild(table);
        if (signature.timestampValid !== true) {
            const note = document.createElement('p');
            note.className = 'signature-warning';
            note.textContent = 'Hinweis: Sealium hat keinen gültigen kryptografischen Zeitstempel bestätigt. Dies ist getrennt vom Ergebnis der Signaturprüfung zu betrachten.';
            signatureResult.appendChild(note);
        }
    } catch (error) {
        showSignatureMessage(error.message || 'Die Signaturprüfung ist fehlgeschlagen.', true);
    }
}

if (signatureLink && signatureResult && signatureDialog && pdfPicker) {
    signatureLink.addEventListener('click', event => {
        event.preventDefault();
        validateSelectedPdf();
    });
    signatureBack?.addEventListener('click', () => signatureDialog.close());
    signaturePrint?.addEventListener('click', () => window.print());
    signatureDialog.addEventListener('click', event => {
        if (event.target === signatureDialog) signatureDialog.close();
    });
}
