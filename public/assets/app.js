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


// Externe Signaturprüfung über Sealium
const signatureLink = document.getElementById('signature-check-link');
const signatureFile = document.getElementById('signature-file');
const signatureResult = document.getElementById('signature-result');
if (signatureLink && signatureFile && signatureResult) {
    signatureLink.addEventListener('click', event => {
        event.preventDefault();
        signatureFile.click();
    });

    signatureFile.addEventListener('change', async () => {
        const file = signatureFile.files && signatureFile.files[0];
        if (!file) return;
        signatureResult.hidden = false;
        signatureResult.className = 'signature-result';
        signatureResult.textContent = 'Signatur wird bei Sealium geprüft …';
        const form = new FormData();
        form.append('pdf', file);
        form.append('csrf', document.querySelector('input[name="csrf"]')?.value || '');
        try {
            const response = await fetch('/validate-signature.php', {method: 'POST', body: form, credentials: 'same-origin'});
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Prüfung fehlgeschlagen.');
            const signature = (data.signatures || [])[0] || {};
            const summary = data.validationSummary || {};
            const rows = [
                ['Dokument', data.filename || file.name],
                ['Gesamtergebnis', data.valid === true ? 'Gültig' : 'Nicht gültig'],
                ['Dokument unverändert', data.documentIntact === true ? 'Ja' : 'Nein'],
                ['Unterzeichner', signature.signerName || signature.signerDn || 'Nicht angegeben'],
                ['Signaturstufe', signature.signatureLevel || 'Nicht angegeben'],
                ['Signatur intakt', signature.signatureIntact === true ? 'Ja' : 'Nein'],
                ['Zertifikat gültig', signature.certValid === true ? 'Ja' : 'Nein'],
                ['Widerrufsprüfung', signature.revocationOk === true ? 'OK' : 'Nicht bestätigt'],
                ['Zeitstempel', signature.timestampValid === true ? 'Gültig' : 'Nicht bestätigt'],
                ['Qualifizierte Signatur', summary.hasQualifiedSignature === true ? 'Ja' : 'Nein'],
                ['Prüfzeitpunkt', data.validatedAt || 'Nicht angegeben']
            ];
            signatureResult.replaceChildren();
            const heading = document.createElement('h3');
            heading.textContent = 'Ergebnis der Signaturprüfung';
            signatureResult.appendChild(heading);
            const status = document.createElement('p');
            status.className = data.valid === true ? 'signature-status good' : 'signature-status bad';
            status.textContent = data.valid === true ? 'Signaturprüfung erfolgreich' : 'Signatur nicht vollständig gültig';
            signatureResult.appendChild(status);
            const table = document.createElement('dl');
            rows.forEach(([label, value]) => {
                const term = document.createElement('dt'); term.textContent = label;
                const detail = document.createElement('dd'); detail.textContent = String(value);
                table.append(term, detail);
            });
            signatureResult.appendChild(table);
            if (signature.timestampValid !== true) {
                const note = document.createElement('p');
                note.className = 'signature-warning';
                note.textContent = 'Hinweis: Sealium hat keinen gültigen kryptografischen Zeitstempel bestätigt. Das ist getrennt vom Ergebnis der Signaturprüfung zu betrachten.';
                signatureResult.appendChild(note);
            }
        } catch (error) {
            signatureResult.className = 'signature-result signature-error';
            signatureResult.textContent = error.message || 'Die Signaturprüfung ist fehlgeschlagen.';
        } finally {
            signatureFile.value = '';
        }
    });
}
