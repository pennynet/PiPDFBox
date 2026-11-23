const picker = document.getElementById('pdfs');
const list = document.getElementById('filelist');
const drop = document.getElementById('pdf-dropzone');
const uploadPrompt = document.getElementById('upload-prompt');
const handbookDialog = document.getElementById('handbook-dialog');
const handbookFrame = document.getElementById('handbook-frame');
document.getElementById('help-link')?.addEventListener('click', event => {
    event.preventDefault();
    handbookFrame.src = '/handbuch.php';
    handbookDialog.showModal();
});
document.getElementById('handbook-close')?.addEventListener('click', () => handbookDialog.close());
handbookDialog?.addEventListener('close', () => {
    handbookFrame.src = 'about:blank';
});

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
    const reverseInputsCheckbox = document.querySelector('input[name="reverse_inputs"]');
    if (reverseInputsCheckbox) {
        reverseInputsCheckbox.disabled = files.length <= 1;
        if (files.length <= 1) reverseInputsCheckbox.checked = false;
    }
    const completionNotice = document.querySelector('.success');
    if (completionNotice && ['Verarbeitung abgeschlossen.', 'PDF wurde erfolgreich signiert.', 'CAdES-Signierung erfolgreich. Original-PDF und Signatur stehen gemeinsam als ZIP bereit.'].includes(completionNotice.textContent.trim())) {
        completionNotice.hidden = files.length === 0 && !list.dataset.resultUrl;
    }
    list.replaceChildren();
    uploadPrompt.hidden = files.length > 0;
    const displayedCount = files.length + (list.dataset.resultUrl ? 1 : 0);
    const columns = displayedCount <= 1 ? 2 : Math.min(displayedCount, 5);
    list.style.gridTemplateColumns = `repeat(${columns}, minmax(0, 1fr))`;
    let clearAll = null;
    if (files.length > 1) {
        clearAll = document.createElement('button');
        clearAll.type = 'button';
        clearAll.className = 'pdf-clear-all';
        clearAll.textContent = '× Alle Dateien entfernen';
        clearAll.title = 'Alle ausgewählten Eingabedateien entfernen';
        clearAll.addEventListener('click', event => {
            event.stopPropagation();
            setFiles([]);
        });
    }
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
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'pdf-chip-remove';
        remove.textContent = '×';
        remove.title = 'Datei entfernen';
        remove.setAttribute('aria-label', file.name + ' entfernen');
        remove.addEventListener('click', () => setFiles(currentFiles().filter((_, i) => i !== index)));
        chip.append(open, remove);
        // Den Prüfbutton nur einblenden, wenn die PDF tatsächlich einen Signaturbereich enthält.
        pdfHasSignature(file).then(hasSignature => {
            if (!hasSignature || !chip.isConnected || !currentFiles().includes(file) || chip.querySelector('.pdf-chip-verify')) return;
            const verify = document.createElement('button');
            verify.type = 'button';
            verify.className = 'pdf-chip-verify';
            verify.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="m8 15 2.5 2.5L16 12"/></svg>';
            verify.title = 'Signatur prüfen';
            verify.setAttribute('aria-label', 'Signatur von ' + file.name + ' prüfen');
            verify.addEventListener('click', () => validateSelectedPdf(file));
            chip.insertBefore(verify, remove);
        }).catch(() => {});
        
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
        const isZip = list.dataset.resultName.toLowerCase().endsWith('.zip');
        const isCades = list.dataset.resultName.toLowerCase().endsWith('.p7s');
        const open = document.createElement('button');
        open.type = 'button';
        open.className = 'pdf-chip-open';
        open.title = list.dataset.resultName + (isZip ? ' – ZIP-Inhalt anzeigen' : ' – Ergebnis öffnen');
        const icon = document.createElement('span');
        icon.className = 'pdf-chip-icon';
        icon.textContent = isZip ? 'ZIP' : (isCades ? 'CMS' : 'PDF');
        const name = document.createElement('span');
        name.className = 'pdf-chip-name';
        name.textContent = list.dataset.resultName;
        open.append(icon, name);
        open.addEventListener('click', () => {
            if (isZip) openZipBrowser();
            else if (!isCades) openPdf(list.dataset.resultUrl + '&inline=1', list.dataset.resultName);
        });

        const download = document.createElement('a');
        download.className = 'pdf-chip-download';
        download.href = list.dataset.resultUrl;
        download.textContent = '↓';
        download.title = 'Ergebnisdatei herunterladen';
        download.setAttribute('aria-label', 'Ergebnisdatei herunterladen');
        if (isCades) {
            open.disabled = true;
            open.title = 'CAdES-Signaturdatei – nicht als PDF darstellbar';
            output.append(open, download);
        } else {
            const reuse = document.createElement('button');
            reuse.type = 'button';
            reuse.className = 'pdf-chip-reuse';
            reuse.textContent = '↳';
            reuse.title = 'Ergebnis als Eingabedatei weiterverarbeiten';
            reuse.setAttribute('aria-label', 'Ergebnisdatei zur weiteren Verarbeitung übernehmen');
            reuse.addEventListener('click', () => reuseResultForProcessing(reuse));
            if (isZip) output.append(open, download);
            else output.append(open, reuse, download);
        }

        if (list.dataset.resultAction === 'sign' && !isCades && !isZip) {
            const verify = document.createElement('button');
            verify.type = 'button';
            verify.className = 'pdf-chip-verify';
            verify.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="m8 15 2.5 2.5L16 12"/></svg>';
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
    if (clearAll) list.appendChild(clearAll);
}

// Beim Seitenaufbau ebenfalls rendern: Nach einem abgeschlossenen Auftrag
// kommt das Ergebnis aus der PHP-Session und muss sofort als Chip erscheinen.
renderFiles();

const officeExtensions = new Set(['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx']);
let conversionInProgress = false;
const conversionStatus = document.createElement('div');
conversionStatus.className = 'settings-status';
conversionStatus.setAttribute('role', 'status');
conversionStatus.setAttribute('aria-live', 'polite');
conversionStatus.hidden = true;
drop.appendChild(conversionStatus);

async function acceptFiles(incoming) {
    if (conversionInProgress) return;
    const existing = currentFiles();
    const additions = [...incoming].filter(file => {
        const ext = file.name.split('.').pop().toLowerCase();
        return file.type === 'application/pdf' || ext === 'pdf' || officeExtensions.has(ext);
    });
    if (!additions.length) {
        alert('Bitte PDF- oder unterstützte Office-Dateien auswählen.');
        return;
    }

    conversionInProgress = true;
    const taskButtons = [...document.querySelectorAll('.task-button')];
    taskButtons.forEach(button => { button.disabled = true; });
    conversionStatus.hidden = false;
    const merged = [...existing];
    try {
        for (let index = 0; index < additions.length; index++) {
            const file = additions[index];
            const extension = file.name.split('.').pop().toLowerCase();
            let readyFile = file;
            if (officeExtensions.has(extension)) {
                conversionStatus.textContent = 'Konvertiere ' + file.name + ' (' + (index + 1) + '/' + additions.length + ') …';
                const body = new FormData();
                body.append('office_file', file, file.name);
                body.append('csrf', drop.dataset.csrf || '');
                const response = await fetch('/convert-office.php', {
                    method: 'POST',
                    body,
                    credentials: 'same-origin',
                    cache: 'no-store'
                });
                if (!response.ok) {
                    let message = 'Office-Datei konnte nicht umgewandelt werden.';
                    try {
                        const data = await response.json();
                        if (data.error) message = data.error;
                    } catch (_) {}
                    throw new Error(file.name + ': ' + message);
                }
                const pdfBlob = await response.blob();
                const pdfName = file.name.replace(/\.[^.]+$/, '') + '.pdf';
                readyFile = new File([pdfBlob], pdfName, {type:'application/pdf', lastModified:Date.now()});
            }
            if (!merged.some(old => old.name === readyFile.name && old.size === readyFile.size && old.lastModified === readyFile.lastModified)) {
                merged.push(readyFile);
            }
            if (merged.length > 100) throw new Error('Maximal 100 Dateien pro Auftrag.');
            if (index === 0) {
                // Once the result is adopted as an input, it must have only
                // one visible chip. Clear every result reference before
                // rendering the updated input list.
                delete list.dataset.resultUrl;
                delete list.dataset.resultName;
                delete list.dataset.resultAction;
                delete list.dataset.resultJob;
                delete list.dataset.resultFile;
                delete list.dataset.resultCsrf;
            }
            setFiles(merged);
        }
        conversionStatus.textContent = 'Dateien sind bereit.';
    } catch (error) {
        alert(error.message || 'Datei konnte nicht verarbeitet werden.');
        conversionStatus.textContent = 'Upload/Umwandlung nicht vollständig.';
    } finally {
        conversionInProgress = false;
        taskButtons.forEach(button => { button.disabled = false; });
        setTimeout(() => { conversionStatus.hidden = true; }, 3500);
    }
}
picker.addEventListener('change', () => acceptFiles(picker.files));

const reverseInputsCheckbox = document.querySelector('input[name="reverse_inputs"]');
reverseInputsCheckbox?.addEventListener('change', () => {
    if (currentFiles().length <= 1) return;
    // The visible file order is authoritative for processing. Toggle the
    // order in the file list itself; the worker must not reverse it again.
    setFiles(currentFiles().reverse());
});
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

let pendingFilenameSubmitter = null;
let bypassFilenamePrefixPrompt = false;
const filenamePrefixDialog = document.getElementById('filename-prefix-dialog');
const filenamePrefixInput = document.getElementById('filename-prefix-input');
document.getElementById('filename-prefix-cancel')?.addEventListener('click', () => {
    filenamePrefixDialog?.close();
    pendingFilenameSubmitter = null;
    bypassSignedPdfWarning = false;
});
document.getElementById('filename-prefix-confirm')?.addEventListener('click', () => {
    const prefix = filenamePrefixInput?.value.trim() || '';
    if (!prefix) {
        filenamePrefixInput?.setCustomValidity('Bitte einen Prefix eingeben.');
        filenamePrefixInput?.reportValidity();
        return;
    }
    filenamePrefixInput?.setCustomValidity('');
    const form = document.querySelector('form.panel');
    let hidden = form.querySelector('input[name="filename_prefix"]');
    if (!hidden) {
        hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'filename_prefix';
        form.appendChild(hidden);
    }
    hidden.value = prefix;
    filenamePrefixDialog.close();
    bypassFilenamePrefixPrompt = true;
    form.requestSubmit(pendingFilenameSubmitter || undefined);
    pendingFilenameSubmitter = null;
});
filenamePrefixDialog?.addEventListener('click', event => {
    if (event.target === filenamePrefixDialog) {
        filenamePrefixDialog.close();
        pendingFilenameSubmitter = null;
        bypassSignedPdfWarning = false;
    }
});

let bypassSignedPdfWarning = false;
let pendingSignedPdfSubmitter = null;
let pendingSignedPdfAction = null;
const signedPdfWarningDialog = document.getElementById('signed-pdf-warning-dialog');
document.getElementById('signed-pdf-warning-cancel')?.addEventListener('click', () => {
    signedPdfWarningDialog?.close();
    pendingSignedPdfSubmitter = null;
    if (pendingSignedPdfAction) {
        const cancelAction = pendingSignedPdfAction.cancel;
        pendingSignedPdfAction = null;
        cancelAction?.();
    }
    bypassSignedPdfWarning = false;
});
document.getElementById('signed-pdf-warning-confirm')?.addEventListener('click', () => {
    signedPdfWarningDialog?.close();
    const button = pendingSignedPdfSubmitter;
    pendingSignedPdfSubmitter = null;
    bypassSignedPdfWarning = true;
    if (button) {
        continueTaskSubmission(button);
    } else if (pendingSignedPdfAction) {
        const action = pendingSignedPdfAction.confirm;
        pendingSignedPdfAction = null;
        action?.();
    }
});

async function continueTaskSubmission(button) {
    const form = document.querySelector('form.panel');
    if (!bypassSignedPdfWarning) {
        for (const file of currentFiles()) {
            if ((file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf')) && await pdfHasSignature(file)) {
                pendingSignedPdfSubmitter = button;
                signedPdfWarningDialog?.showModal();
                return;
            }
        }
    }
    if (!bypassFilenamePrefixPrompt && form.dataset.filenameMode === 'date_prefix') {
        pendingFilenameSubmitter = button;
        if (filenamePrefixInput) filenamePrefixInput.value = '';
        filenamePrefixDialog?.showModal();
        filenamePrefixInput?.focus();
        return;
    }

    bypassFilenamePrefixPrompt = false;
    bypassSignedPdfWarning = false;
    if (button.value === 'sign') {
        const files = currentFiles();
        if (files.length === 0) {
            alert('Bitte zuerst eine PDF-Datei auswählen.');
            return;
        }
        if (files.length > 2) {
            signLimitDialog.showModal();
            return;
        }
    }
    const result = document.querySelector('.result');
    if (result) result.remove();
    const action = document.createElement('input');
    action.type = 'hidden';
    action.name = 'action';
    action.value = button.value;
    form.appendChild(action);
    const success = document.querySelector('.success');
    if (success) success.textContent = 'Verarbeitung läuft …';
    button.disabled = true;
    button.textContent = 'Verarbeitung läuft …';
    form.submit();
}

document.querySelector('form.panel').addEventListener('submit', event => {
    const button = event.submitter || document.activeElement;
    if (!button?.classList.contains('task-button')) return;
    // Sofort verhindern: Ein async-Handler darf erst nach dem await preventDefault() aufrufen.
    event.preventDefault();
    continueTaskSubmission(button);
});

// Statusmeldungen automatisch ausblenden: laufende Verarbeitung bleibt sichtbar.
document.querySelectorAll('.success').forEach(message => {
    if (message.textContent.trim() === 'Verarbeitung läuft …') return;
    setTimeout(() => {
        message.style.transition = 'opacity .3s ease';
        message.style.opacity = '0';
        setTimeout(() => message.remove(), 350);
    }, 5000);
});
document.querySelectorAll('.alert').forEach(message => {
    setTimeout(() => {
        message.style.transition = 'opacity .3s ease';
        message.style.opacity = '0';
        setTimeout(() => message.remove(), 350);
    }, 10000);
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
const filenameDialog = document.getElementById('filename-dialog');
document.getElementById('open-filename-settings')?.addEventListener('click', () => {
    settingsMenu?.close();
    filenameDialog?.showModal();
});
document.getElementById('filename-dialog-cancel')?.addEventListener('click', () => filenameDialog?.close());
filenameDialog?.addEventListener('click', event => {
    if (event.target === filenameDialog) filenameDialog.close();
});


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

// PDF-Signaturen sind im PDF-Quelltext durch ein /ByteRange-Feld
// gekennzeichnet. Die Suche erfolgt blockweise, damit große PDFs nicht
// vollständig zusätzlich in den Arbeitsspeicher geladen werden.
async function pdfHasSignature(file) {
    const marker = new TextEncoder().encode('/ByteRange');
    const chunkSize = 1024 * 1024;
    let carry = new Uint8Array(0);
    for (let offset = 0; offset < file.size; offset += chunkSize) {
        const chunk = new Uint8Array(await file.slice(offset, offset + chunkSize).arrayBuffer());
        const data = new Uint8Array(carry.length + chunk.length);
        data.set(carry);
        data.set(chunk, carry.length);
        outer: for (let i = 0; i <= data.length - marker.length; i++) {
            for (let j = 0; j < marker.length; j++) {
                if (data[i + j] !== marker[j]) continue outer;
            }
            return true;
        }
        carry = data.slice(Math.max(0, data.length - marker.length + 1));
    }
    return false;
}

async function reuseResultForProcessing(button) {
    button.disabled = true;
    button.title = 'Wird übernommen …';
    try {
        const response = await fetch(list.dataset.resultUrl, {credentials:'same-origin', cache:'no-store'});
        if (!response.ok) throw new Error('Ergebnisdatei konnte nicht geladen werden.');
        const blob = await response.blob();
        const name = list.dataset.resultName || 'pipdfbox-ergebnis.pdf';
        const file = new File([blob], name, {type:'application/pdf', lastModified:Date.now()});
        if (await pdfHasSignature(file)) {
            pendingSignedPdfAction = {
                confirm: () => {
                    acceptFiles([file]);
                    const firstTask = document.querySelector('.tool-grid');
                    firstTask?.scrollIntoView({behavior:'smooth', block:'nearest'});
                },
                cancel: () => {
                    button.disabled = false;
                    button.title = 'Ergebnis als Eingabedatei weiterverarbeiten';
                }
            };
            signedPdfWarningDialog?.showModal();
            return;
        }
        acceptFiles([file]);
        const firstTask = document.querySelector('.tool-grid');
        firstTask?.scrollIntoView({behavior:'smooth', block:'nearest'});
    } catch (error) {
        button.disabled = false;
        button.title = 'Ergebnis als Eingabedatei weiterverarbeiten';
        alert(error.message || 'Ergebnisdatei konnte nicht übernommen werden.');
    }
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
        // Deleting the server-side result must not remove an in-memory
        // copy that was already adopted as an input file.
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


/* ZIP-Ergebnis im integrierten Dateibrowser anzeigen. */
const zipDialog = document.getElementById('zip-browser-dialog');
const zipList = document.getElementById('zip-browser-list');
const zipTitle = document.getElementById('zip-browser-title');
const zipPathLabel = document.getElementById('zip-browser-path');
const zipUp = document.getElementById('zip-browser-up');
const zipImport = document.getElementById('zip-browser-import');
let zipCurrentPath = '';
const zipSelectedPdfs = new Set();

function zipJoinPath(base, name) {
    return (base ? base.replace(/\/$/, '') + '/' : '') + name;
}
function updateZipImportButton() {
    if (!zipImport) return;
    zipImport.disabled = zipSelectedPdfs.size === 0;
    zipImport.textContent = 'Auswahl übernehmen (' + zipSelectedPdfs.size + ')';
}
async function loadZipDirectory(path = '') {
    zipCurrentPath = path;
    zipList.replaceChildren();
    zipPathLabel.textContent = '/' + path;
    zipUp.disabled = !path;
    const loading = document.createElement('p');
    loading.className = 'zip-browser-message';
    loading.textContent = 'Inhalt wird geladen …';
    zipList.appendChild(loading);
    try {
        const url = '/zip-content.php?mode=list&job=' + encodeURIComponent(list.dataset.resultJob)
            + '&file=' + encodeURIComponent(list.dataset.resultFile)
            + '&path=' + encodeURIComponent(path);
        const response = await fetch(url, {credentials:'same-origin', cache:'no-store'});
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'ZIP-Inhalt konnte nicht gelesen werden.');
        zipList.replaceChildren();
        if (!data.entries.length) {
            const empty = document.createElement('p');
            empty.className = 'zip-browser-message';
            empty.textContent = 'Dieser Ordner ist leer.';
            zipList.appendChild(empty);
        }
        data.entries.forEach(entry => {
            const row = document.createElement('div');
            row.className = 'zip-entry';
            const openEntry = document.createElement('button');
            openEntry.type = 'button';
            openEntry.className = 'zip-entry-open';
            const entryIcon = document.createElement('span');
            entryIcon.className = 'zip-entry-icon';
            entryIcon.textContent = entry.directory ? '📁' : (entry.name.toLowerCase().endsWith('.pdf') ? 'PDF' : '📄');
            const entryName = document.createElement('span');
            entryName.className = 'zip-entry-name';
            entryName.textContent = entry.name;
            const entrySize = document.createElement('span');
            entrySize.className = 'zip-entry-size';
            entrySize.textContent = entry.directory ? 'Ordner' : formatZipSize(entry.size);
            openEntry.append(entryIcon, entryName, entrySize);
            openEntry.addEventListener('click', async () => {
                const entryPath = zipJoinPath(zipCurrentPath, entry.name);
                if (entry.directory) {
                    loadZipDirectory(entryPath);
                } else if (entry.name.toLowerCase().endsWith('.pdf')) {
                    try {
                        const blob = await fetchZipEntry(entryPath);
                        openPdf(new File([blob], entry.name, {type:'application/pdf'}));
                    } catch (error) {
                        alert(error.message);
                    }
                } else {
                    downloadZipEntry(entryPath);
                }
            });
            row.appendChild(openEntry);

            const entryPath = zipJoinPath(zipCurrentPath, entry.name);
            if (!entry.directory && entry.name.toLowerCase().endsWith('.pdf')) {
                const actions = document.createElement('div');
                actions.className = 'zip-entry-actions';
                const download = document.createElement('button');
                download.type = 'button';
                download.className = 'zip-entry-download';
                download.textContent = '↓';
                download.title = 'PDF herunterladen';
                download.setAttribute('aria-label', entry.name + ' herunterladen');
                download.addEventListener('click', () => downloadZipEntry(entryPath));
                const label = document.createElement('label');
                label.className = 'zip-entry-select';
                label.title = 'PDF zur Weiterverarbeitung auswählen';
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.checked = zipSelectedPdfs.has(entryPath);
                checkbox.setAttribute('aria-label', entry.name + ' auswählen');
                checkbox.addEventListener('change', () => {
                    if (checkbox.checked) zipSelectedPdfs.add(entryPath);
                    else zipSelectedPdfs.delete(entryPath);
                    updateZipImportButton();
                });
                label.appendChild(checkbox);
                actions.append(download, label);
                row.appendChild(actions);
            }
            zipList.appendChild(row);
        });
    } catch (error) {
        zipList.replaceChildren();
        const message = document.createElement('p');
        message.className = 'zip-browser-message zip-browser-error';
        message.textContent = error.message || 'ZIP-Inhalt konnte nicht geladen werden.';
        zipList.appendChild(message);
    }
}
function formatZipSize(bytes) {
    if (!Number.isFinite(bytes) || bytes < 0) return '';
    if (bytes < 1024) return bytes + ' B';
    const units = ['KB','MB','GB'];
    let size = bytes / 1024, unit = units[0];
    for (let i=1; size >= 1024 && i<units.length; i++) { size /= 1024; unit=units[i]; }
    return size.toLocaleString('de-DE',{maximumFractionDigits:1}) + ' ' + unit;
}
function zipEntryUrl(path) {
    return '/zip-content.php?mode=download&job=' + encodeURIComponent(list.dataset.resultJob)
        + '&file=' + encodeURIComponent(list.dataset.resultFile)
        + '&entry=' + encodeURIComponent(path);
}
async function fetchZipEntry(path) {
    const response = await fetch(zipEntryUrl(path), {credentials:'same-origin', cache:'no-store'});
    if (!response.ok) throw new Error('Datei konnte nicht aus dem ZIP gelesen werden.');
    return response.blob();
}
function downloadZipEntry(path) {
    const link = document.createElement('a');
    link.href = zipEntryUrl(path);
    link.download = path.split('/').pop() || 'datei';
    document.body.appendChild(link);
    link.click();
    link.remove();
}
async function importSelectedZipPdfs() {
    if (!zipSelectedPdfs.size) return;
    if (currentFiles().length + zipSelectedPdfs.size > 100) {
        alert('Maximal 100 PDF-Dateien pro Auftrag.');
        return;
    }
    zipImport.disabled = true;
    const originalText = zipImport.textContent;
    zipImport.textContent = 'PDF-Dateien werden übernommen …';
    try {
        const files = [];
        for (const path of zipSelectedPdfs) {
            const blob = await fetchZipEntry(path);
            files.push(new File([blob], path.split('/').pop() || 'Dokument.pdf', {
                type:'application/pdf', lastModified:Date.now()
            }));
        }
        acceptFiles(files);
        zipSelectedPdfs.clear();
        updateZipImportButton();
        zipDialog.close();
    } catch (error) {
        alert(error.message || 'Ausgewählte PDF-Dateien konnten nicht übernommen werden.');
    } finally {
        zipImport.textContent = originalText;
        updateZipImportButton();
    }
}
function openZipBrowser() {
    if (!zipDialog || !list.dataset.resultJob || !list.dataset.resultFile) return;
    zipTitle.textContent = list.dataset.resultName;
    zipSelectedPdfs.clear();
    updateZipImportButton();
    zipDialog.showModal();
    loadZipDirectory('');
}
zipUp?.addEventListener('click', () => {
    const parts = zipCurrentPath.split('/').filter(Boolean);
    parts.pop();
    loadZipDirectory(parts.join('/'));
});
zipImport?.addEventListener('click', importSelectedZipPdfs);
document.getElementById('zip-browser-close')?.addEventListener('click', () => zipDialog.close());
