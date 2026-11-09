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
