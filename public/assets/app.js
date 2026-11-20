const picker = document.getElementById('pdfs');
const list = document.getElementById('filelist');
const drop = document.getElementById('pdf-dropzone');
const uploadPrompt = document.getElementById('upload-prompt');
const viewerDialog = document.getElementById('pdf-viewer-dialog');
const viewerFrame = document.getElementById('pdf-viewer-frame');
const viewerTitle = document.getElementById('pdf-viewer-title');
let viewerUrl = null;
let draggedIndex = null;
let selectedFiles = [];

let pointerDrag = null;
let suppressChipClick = false;

function beginVisualDrag(event) {
    const state = pointerDrag;
    if (!state || state.active) return;
    state.active = true;
    suppressChipClick = true;
    const rect = state.chip.getBoundingClientRect();
    const placeholder = document.createElement('div');
    placeholder.className = 'pdf-chip-placeholder';
    placeholder.style.width = rect.width + 'px';
    placeholder.style.height = rect.height + 'px';
    const ghost = state.chip.cloneNode(true);
    ghost.classList.add('pdf-chip-ghost');
    ghost.style.width = rect.width + 'px';
    ghost.style.height = rect.height + 'px';
    ghost.style.left = rect.left + 'px';
    ghost.style.top = rect.top + 'px';
    document.body.appendChild(ghost);
    state.chip.replaceWith(placeholder);
    state.placeholder = placeholder;
    state.ghost = ghost;
    state.offsetX = event.clientX - rect.left;
    state.offsetY = event.clientY - rect.top;
}

document.addEventListener('pointermove', event => {
    if (!pointerDrag) return;
    const dx = event.clientX - pointerDrag.startX;
    const dy = event.clientY - pointerDrag.startY;
    if (!pointerDrag.active && Math.hypot(dx, dy) < 5) beginVisualDrag(event);
    if (!pointerDrag.active) return;

    pointerDrag.ghost.style.left = (event.clientX - pointerDrag.offsetX) + 'px';
    pointerDrag.ghost.style.top = (event.clientY - pointerDrag.offsetY) + 'px';

    const target = document.elementFromPoint(event.clientX, event.clientY)?.closest('.pdf-chip:not(.pdf-chip-result)');
    if (!target || !list.contains(target)) return;
    const rect = target.getBoundingClientRect();
    const after = event.clientX > rect.left + rect.width / 2;
    const placeholder = pointerDrag.placeholder;
    if (after) {
        if (target.nextElementSibling !== placeholder) list.insertBefore(placeholder, target.nextElementSibling);
    } else if (placeholder.nextElementSibling !== target) {
        list.insertBefore(placeholder, target);
    }
});

document.addEventListener('pointerup', () => {
    if (!pointerDrag) return;
    const state = pointerDrag;
    if (state.active) {
        const original = currentFiles();
        state.ghost.remove();
        state.placeholder.replaceWith(state.chip);
        const order = [...list.querySelectorAll('.pdf-chip:not(.pdf-chip-result)')].map(item => Number(item.dataset.index));
        if (order.length === original.length && order.every(Number.isInteger)) {
            setFiles(order.map(i => original[i]));
        } else {
            renderFiles();
        }
        setTimeout(() => { suppressChipClick = false; }, 0);
    }
    pointerDrag = null;
});

function currentFiles() { return [...selectedFiles]; }
function setFiles(files) {
    selectedFiles = [...files];
    const transfer = new DataTransfer();
    files.forEach(file => transfer.items.add(file));
    picker.files = transfer.files;
    renderFiles();
}
function renderFiles() {
    const files = currentFiles();
    list.replaceChildren();
    uploadPrompt.hidden = files.length > 0;
    if (!files.length && !list.dataset.resultUrl) {
        const empty = document.createElement('span');
        empty.className = 'pdf-empty';
        empty.textContent = 'Noch keine Dateien ausgewählt';
        list.appendChild(empty);
    }
    files.forEach((file, index) => {
        const chip = document.createElement('div');
        chip.className = 'pdf-chip';
        chip.dataset.index = String(index);
        const open = document.createElement('button');
        open.type = 'button';
        open.className = 'pdf-chip-open';
        open.title = file.name + ' – anklicken zum Öffnen, rechtsklicken zur Signaturprüfung';
        const icon = document.createElement('span');
        icon.className = 'pdf-chip-icon';
        icon.textContent = 'PDF';
        const name = document.createElement('span');
        name.className = 'pdf-chip-name';
        name.textContent = file.name;
        open.append(icon, name);
        open.addEventListener('click', () => {
            if (suppressChipClick) return;
            openPdf(file);
        });
        open.addEventListener('contextmenu', event => {
            event.preventDefault();
            validateSelectedPdf(file);
        });
        const verify = document.createElement('button');
        verify.type = 'button';
        verify.className = 'pdf-chip-verify';
        verify.textContent = '⋯';
        verify.title = 'Signatur prüfen';
        verify.setAttribute('aria-label', 'Signatur von ' + file.name + ' prüfen');
        verify.addEventListener('click', () => validateSelectedPdf(file));
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'pdf-chip-remove';
        remove.textContent = '×';
        remove.title = 'Datei entfernen';
        remove.setAttribute('aria-label', file.name + ' entfernen');
        remove.addEventListener('click', () => setFiles(currentFiles().filter((_, i) => i !== index)));
        chip.append(open, verify, remove);

        // Zuverlässiges Maus-Verschieben per Pointer Events statt HTML5-Drag-and-drop.
        chip.addEventListener('pointerdown', event => {
            if (event.pointerType !== 'mouse' || event.button !== 0 || event.target.closest('.pdf-chip-verify,.pdf-chip-remove')) return;
            pointerDrag = {chip, startX:event.clientX, startY:event.clientY, active:false};
        });
        list.appendChild(chip);
    });
    if (list.dataset.resultUrl) {
        const output = document.createElement('div');
        output.className = 'pdf-chip pdf-chip-result';
        const open = document.createElement('button');
        open.type = 'button';
        open.className = 'pdf-chip-open';
        open.title = list.dataset.resultName + ' – Ergebnis öffnen';
        const icon = document.createElement('span');
        icon.className = 'pdf-chip-icon';
        icon.textContent = 'PDF';
        const name = document.createElement('span');
        name.className = 'pdf-chip-name';
        name.textContent = list.dataset.resultName;
        open.append(icon, name);
        open.addEventListener('click', () => openPdf(list.dataset.resultUrl + '&inline=1', list.dataset.resultName));
        const download = document.createElement('a');
        download.className = 'pdf-chip-download';
        download.href = list.dataset.resultUrl;
        download.textContent = '↓';
        download.title = 'Ergebnisdatei herunterladen';
        download.setAttribute('aria-label', 'Ergebnisdatei herunterladen');
        output.append(open, download);

        if (list.dataset.resultAction === 'sign') {
            const verify = document.createElement('button');
            verify.type = 'button';
            verify.className = 'pdf-chip-verify';
            verify.textContent = '⋯';
            verify.title = 'Signatur prüfen';
            verify.setAttribute('aria-label', 'Signatur der Ausgabedatei prüfen');
            verify.addEventListener('click', () => validateResultPdf());
            output.appendChild(verify);
        }

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'pdf-chip-remove';
        remove.textContent = '×';
        remove.title = 'Ausgabedatei löschen';
        remove.setAttribute('aria-label', 'Ausgabedatei löschen');
        remove.addEventListener('click', () => deleteResultFile(remove));
        output.appendChild(remove);
        list.appendChild(output);
    }
}

// Beim Seitenaufbau ebenfalls rendern: Nach einem abgeschlossenen Auftrag
// kommt das Ergebnis aus der PHP-Session und muss sofort als Chip erscheinen.
renderFiles();

function acceptFiles(incoming) {
    const existing = currentFiles();
    const additions = [...incoming].filter(file =>
        file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf')
    );
    // Sobald ein neuer Upload beginnt, ist ein zuvor erzeugtes Ergebnis veraltet.
    // Sofort aus der Kachel entfernen; serverseitig wird es beim neuen Auftrag
    // zusätzlich vor der Verarbeitung aus der Session gelöscht.
    if (additions.length > 0) {
        delete list.dataset.resultUrl;
        delete list.dataset.resultName;
    }
    const merged = [...existing];
    additions.forEach(file => {
        if (!merged.some(old => old.name === file.name && old.size === file.size && old.lastModified === file.lastModified)) merged.push(file);
    });
    if (merged.length > 100) {
        alert('Maximal 100 PDF-Dateien pro Auftrag.');
        return;
    }
    setFiles(merged);
}
picker.addEventListener('change', () => acceptFiles(picker.files));
drop.addEventListener('click', event => {
    if (event.target.closest('.pdf-chip')) return;
    if (event.target === picker) return;
    picker.click();
});
for (const ev of ['dragenter', 'dragover']) drop.addEventListener(ev, event => {
    event.preventDefault();
    drop.classList.add('hover');
});
for (const ev of ['dragleave', 'drop']) drop.addEventListener(ev, event => {
    if (event.type === 'drop' && event.target.closest('.pdf-chip')) return;
    event.preventDefault();
    drop.classList.remove('hover');
});
drop.addEventListener('drop', event => {
    if (event.target.closest('.pdf-chip')) return;
    event.preventDefault();
    acceptFiles(event.dataTransfer.files);
});
function openPdf(fileOrUrl, name = '') {
    if (viewerUrl && viewerUrl.startsWith('blob:')) URL.revokeObjectURL(viewerUrl);
    viewerUrl = typeof fileOrUrl === 'string' ? fileOrUrl : URL.createObjectURL(fileOrUrl);
    viewerTitle.textContent = typeof fileOrUrl === 'string' ? name : fileOrUrl.name;
    viewerFrame.src = viewerUrl;
    viewerDialog.showModal();
}
document.getElementById('pdf-viewer-close')?.addEventListener('click', () => viewerDialog.close());
viewerDialog?.addEventListener('close', () => {
    viewerFrame.src = 'about:blank';
    if (viewerUrl && viewerUrl.startsWith('blob:')) URL.revokeObjectURL(viewerUrl);
    viewerUrl = null;
});
const signLimitDialog = document.getElementById('sign-limit-dialog');
document.getElementById('sign-limit-close')?.addEventListener('click', () => signLimitDialog.close());

document.querySelector('form.panel').addEventListener('submit', event => {
    const button = event.submitter || document.activeElement;
    if (button && button.value === 'sign') {
        const files = currentFiles();
        if (files.length === 0) {
            event.preventDefault();
            alert('Bitte zuerst eine PDF-Datei auswählen.');
            return;
        }
        if (files.length > 2) {
            event.preventDefault();
            signLimitDialog.showModal();
            return;
        }
    }
    const result = document.querySelector('.result');
    if (result) result.remove();
    if (button && button.classList.contains('task-button')) {
        const action = document.createElement('input');
        action.type = 'hidden'; action.name = 'action'; action.value = button.value;
        button.form.appendChild(action);
        const success = document.querySelector('.success');
        if (success) success.textContent = 'Verarbeitung läuft …';
        button.disabled = true; button.textContent = 'Verarbeitung läuft …';
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

async function validateResultPdf() {
    signatureDialog.showModal();
    showSignatureMessage('Signierte Ausgabedatei wird geladen …');
    try {
        const response = await fetch(list.dataset.resultUrl, {credentials:'same-origin', cache:'no-store'});
        if (!response.ok) throw new Error('Ausgabedatei konnte nicht geladen werden.');
        const blob = await response.blob();
        const file = new File([blob], list.dataset.resultName || 'signiert.pdf', {type:'application/pdf'});
        await validateSelectedPdf(file);
    } catch (error) {
        showSignatureMessage(error.message || 'Ausgabedatei konnte nicht geprüft werden.', true);
    }
}

async function deleteResultFile(button) {
    button.disabled = true;
    try {
        const body = new URLSearchParams({
            job: list.dataset.resultJob || '',
            file: list.dataset.resultFile || '',
            csrf: list.dataset.resultCsrf || ''
        });
        const response = await fetch('/delete-result.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body,
            credentials: 'same-origin'
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'Ausgabedatei konnte nicht gelöscht werden.');
        delete list.dataset.resultUrl;
        delete list.dataset.resultName;
        delete list.dataset.resultAction;
        delete list.dataset.resultJob;
        delete list.dataset.resultFile;
        delete list.dataset.resultCsrf;
        renderFiles();
    } catch (error) {
        button.disabled = false;
        alert(error.message || 'Löschen fehlgeschlagen.');
    }
}

async function validateSelectedPdf(selectedFile = null) {
    const files = selectedFile ? [selectedFile] : currentFiles();
    if (files.length !== 1) {
        signatureDialog.showModal();
        showSignatureMessage(files.length === 0
            ? 'Bitte zuerst eine PDF-Datei auswählen.'
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
            return `${part('day')}-${part('month')}-${part('year')} ${part('hour')}:${part('minute')}:${part('second')} ${zone}`.trim();
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
