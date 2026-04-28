let appConfig = {};
const state = { left: { path: '', selection: [], files: [] }, right: { path: '', selection: [], files: [] } };
let clipboard = { type: null, files: [], sourcePath: '' };
let lastCleanupData = null; 
let sizeFormat = 'human'; 
let deleteTargetSide = null; 

let sortConfig = { left: { col: 'name', dir: 'asc' }, right: { col: 'name', dir: 'asc' } };
try { const saved = JSON.parse(localStorage.getItem('cc_sort')); if (saved) sortConfig = saved; } catch(e) {}

function formatPerms(octal) {
    const chars = ['---', '--x', '-w-', '-wx', 'r--', 'r-x', 'rw-', 'rwx'];
    const s = octal.slice(-3);
    return Array.from(s).map(n => chars[parseInt(n)] || '???').join('');
}

function formatSize(bytes) {
    if (bytes === '-') return '-';
    if (sizeFormat === 'bytes') return bytes.toString().replace(/\B(?=(\d{3})+(?!\d))/g, " ") + ' B';
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function toggleSizeFormat(e) {
    e.stopPropagation(); 
    sizeFormat = sizeFormat === 'human' ? 'bytes' : 'human';
    reloadBoth();
}

function gotoPath(side, path) {
    state[side].path = path;
    loadFiles(side);
}

function changeSort(side, col) {
    if (sortConfig[side].col === col) {
        sortConfig[side].dir = sortConfig[side].dir === 'asc' ? 'desc' : 'asc';
    } else {
        sortConfig[side].col = col;
        sortConfig[side].dir = 'asc';
    }
    try { localStorage.setItem('cc_sort', JSON.stringify(sortConfig)); } catch(e) {}
    renderList(side, { files: state[side].files });
}

async function gotoTrash(side) {
    const res = await fetch(`api.php?action=list&path=`);
    const data = await res.json();
    if (data.files && data.files.some(f => f.name === '.trash' && f.isDir)) {
        gotoPath(side, '.trash');
    }
}

async function init() {
    const res = await fetch('api.php?action=init');
    appConfig = await res.json(); 
    
    document.body.setAttribute('data-theme', appConfig.theme);
    if (appConfig.window_title) document.title = appConfig.window_title;
    if (appConfig.use_trash) document.querySelectorAll('.btn-trash').forEach(b => b.style.display = 'inline-block');
    
    state.left.path = appConfig.panes.left;
    state.right.path = appConfig.panes.right;

    if (appConfig.refresh_interval > 0) {
        setInterval(reloadBoth, appConfig.refresh_interval * 1000);
    }
    
    setInterval(pollTasks, 1000); 

    reloadBoth();
}

async function reloadBoth() {
    const res = await fetch('api.php?action=batch', {
        method: 'POST',
        body: JSON.stringify({
            requests: [
                { action: 'list', path: state.left.path },
                { action: 'list', path: state.right.path }
            ]
        })
    });
    const data = await res.json();
    if (data.responses) {
        renderList('left', data.responses[0]);
        renderList('right', data.responses[1]);
    }
}

async function loadFiles(side) {
    const res = await fetch(`api.php?action=list&path=${encodeURIComponent(state[side].path)}`);
    const data = await res.json();
    renderList(side, data);
}

function renderList(side, data) {
    const oldSel = [...state[side].selection]; 
    state[side].selection = [];
    
    if (data.error) { showToast(data.error, 'error'); updateToolbar(side); return; }
    if (data.files) state[side].files = data.files;
    
    const list = document.getElementById(`list-${side}`);
    list.innerHTML = '';
    
    if (state[side].path !== '') {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td colspan="5" style="color:var(--accent); cursor:pointer">🔙 .. Назад</td>`;
        tr.onclick = () => goUp(side);
        list.appendChild(tr);
    }

    let filesToRender = [...state[side].files];
    const { col, dir } = sortConfig[side];
    const mult = dir === 'asc' ? 1 : -1;
    
    filesToRender.sort((a, b) => {
        if (a.isDir !== b.isDir) return b.isDir ? 1 : -1; 
        if (col === 'name') return mult * a.name.localeCompare(b.name);
        if (col === 'date') return mult * (a.mtime - b.mtime);
        return 0;
    });

    ['name', 'date'].forEach(c => {
        const el = document.getElementById(`sort-${side}-${c}`);
        if (el) el.innerText = (c === col) ? (dir === 'asc' ? ' ▲' : ' ▼') : '';
    });

    filesToRender.forEach(f => {
        const tr = document.createElement('tr');
        if (oldSel.includes(f.name)) { state[side].selection.push(f.name); tr.classList.add('selected'); }
        if (clipboard.type === 'cut' && clipboard.sourcePath === state[side].path && clipboard.files.includes(f.name)) tr.classList.add('clipboard-cut');
        // Добавлен вывод 🔗 для ссылок
        tr.innerHTML = `
            <td title="${f.name}">${f.isDir?'📁':'📄'} ${f.isLink?'🔗 ':''}${f.name}</td>
            <td class="clickable-size" onclick="toggleSizeFormat(event)">${formatSize(f.size)}</td>
            <td>${f.owner_group}</td>
            <td title="${f.perms}" style="font-family:monospace">${formatPerms(f.perms)}</td>
            <td>${f.modified}</td>
        `;
        tr.onclick = (e) => toggleSelect(side, f.name, tr, e.ctrlKey);
        tr.ondblclick = () => f.isDir ? enterFolder(side, f.name) : openEditor(side, f.name, f.size);
        list.appendChild(tr);
    });
    
    const pathBar = document.getElementById(`path-${side}`);
    pathBar.innerHTML = '';
    
    const root = document.createElement('span');
    root.className = 'breadcrumb';
    root.innerText = '🏠 /';
    root.onclick = () => gotoPath(side, '');
    pathBar.appendChild(root);

    if (state[side].path) {
        const parts = state[side].path.split('/');
        let acc = '';
        parts.forEach((part, index) => {
            acc += (acc ? '/' : '') + part;
            
            const crumb = document.createElement('span');
            crumb.className = 'breadcrumb';
            crumb.innerText = part;
            let targetPath = acc; 
            crumb.onclick = () => gotoPath(side, targetPath);
            pathBar.appendChild(crumb);
            
            if (index < parts.length - 1) {
                const sep = document.createElement('span');
                sep.className = 'breadcrumb-sep';
                sep.innerText = '/';
                pathBar.appendChild(sep);
            }
        });
    }

    updateToolbar(side);
}

function doDownload(side) {
    const name = state[side].selection[0];
    if (!name) return;
    const url = `api.php?action=download&path=${encodeURIComponent(state[side].path)}&name=${encodeURIComponent(name)}`;
    
    const a = document.createElement('a');
    a.style.display = 'none';
    a.href = url;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

async function doPaste(side) {
    if (!clipboard.type) return;
    const transferData = {
        from_path: clipboard.sourcePath, 
        to_path: state[side].path, 
        names: clipboard.files, 
        type: clipboard.type
    };

    if (clipboard.type === 'cut') lastCleanupData = transferData;

    clipboard = { type: null, files: [], sourcePath: '' };
    updateToolbar('left'); updateToolbar('right');

    await fetch(`api.php?action=transfer_os`, {
        method: 'POST', body: JSON.stringify(transferData)
    });

    showToast("Процессы запущены в фоне ОС");
    pollTasks(); 
}

async function pollTasks() {
    const res = await fetch('api.php?action=poll_tasks');
    const tasks = await res.json();
    const container = document.getElementById('tasks-container');
    
    const taskList = Object.values(tasks);
    if (taskList.length === 0) {
        if (container.style.display === 'flex') {
            reloadBoth(); 
            if (lastCleanupData) {
                fetch('api.php?action=cleanup_dirs', { method: 'POST', body: JSON.stringify(lastCleanupData) });
                lastCleanupData = null;
            }
        }
        container.style.display = 'none'; 
        return; 
    }
    
    container.style.display = 'flex';
    container.innerHTML = taskList.map(t => {
        const isNative = (t.native === true) && (t.status === 'running');
        const percent = t.size === 0 ? 100 : Math.round((t.offset / t.size) * 100);
        
        const displayPercent = isNative ? 'ОС...' : `${percent}%`;
        const widthPercent = isNative ? 100 : percent;
        
        let statusIcon = '🔄';
        if (t.status === 'completed') statusIcon = '✅';
        else if (t.status === 'cancelled') statusIcon = '🚫';
        else if (t.status === 'error') statusIcon = '⚠️';
        else if (t.status === 'paused') statusIcon = '⏸️';

        return `
            <div class="task-card">
                <div class="task-header">
                    <span title="${t.name}">${statusIcon} ${t.type==='copy'?'Копия':'Перенос'}: ${t.name.split('/').pop()} (${displayPercent})</span>
                    <div>
                        ${t.status === 'running' || t.status === 'paused' ? 
                            `<button class="task-btn" onclick="controlTask('${t.id}', '${t.status === 'paused' ? 'running' : 'paused'}')" title="Пауза">⏸️</button>
                             <button class="task-btn" style="color:#dc3545" onclick="controlTask('${t.id}', 'cancel')" title="Отмена">✖</button>` 
                            : `<button class="task-btn" onclick="clearTask('${t.id}')" title="Убрать из списка">🗑️</button>`
                        }
                    </div>
                </div>
                <div class="task-progress-bg">
                    <div class="task-progress-fill ${isNative ? 'native-progress' : ''}" 
                         style="width:${widthPercent}%; background:${t.status==='error'||t.status==='cancelled'?'#dc3545':(t.status==='completed'?'#28a745':'var(--accent)')}">
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

async function controlTask(id, cmd) {
    await fetch('api.php?action=control_task', { method: 'POST', body: JSON.stringify({id, cmd}) });
    pollTasks(); 
}

async function clearTask(id) {
    await fetch('api.php?action=clear_task', { method: 'POST', body: JSON.stringify({id}) });
    pollTasks();
}

function toggleSelect(side, name, row, ctrl) {
    if (!ctrl) { state[side].selection = [name]; Array.from(row.parentNode.children).forEach(r => r.classList.remove('selected')); }
    else { state[side].selection.includes(name) ? state[side].selection = state[side].selection.filter(n => n!==name) : state[side].selection.push(name); }
    row.classList.toggle('selected', state[side].selection.includes(name)); updateToolbar(side);
}

function updateToolbar(side) {
    const count = state[side].selection.length;
    const pane = document.getElementById(`pane-${side}`);
    if (!pane) return;
    pane.querySelectorAll('.needs-sel').forEach(b => b.disabled = count === 0);
    pane.querySelectorAll('.needs-one').forEach(b => b.disabled = count !== 1);
    document.querySelectorAll('.btn-paste').forEach(b => b.disabled = !clipboard.type);
}

async function apiCall(action, side, body = {}) {
    const res = await fetch(`api.php?action=${action}&path=${encodeURIComponent(state[side].path)}`, { method: 'POST', body: JSON.stringify(body) });
    const data = await res.json();
    if (data.confirm && confirm(data.confirm)) return apiCall(action, side, {...body, overwrite: true});
    
    if (data.error) showToast(data.error, 'error');
    
    if (data.trash_started) {
        lastCleanupData = data.trash_cleanup;
        showToast("Перемещение в корзину запущено в фоне");
        pollTasks();
    }
    
    reloadBoth(); 
}

function showToast(m, t='') {
    const c = document.getElementById('toast-container');
    const b = document.createElement('div'); b.className = `toast ${t}`; b.innerText = m;
    c.appendChild(b); setTimeout(() => b.remove(), 3000);
}

function refreshPane(side) { loadFiles(side); showToast('Обновлено'); }
function addToClipboard(side, type) { clipboard = { type, files: [...state[side].selection], sourcePath: state[side].path }; showToast(type === 'copy' ? 'Скопировано в буфер' : 'Вырезано в буфер'); reloadBoth(); }

function doDelete(s) { 
    const isTrash = state[s].path.split('/')[0] === '.trash';
    if (appConfig.use_trash && !isTrash) {
        deleteTargetSide = s;
        document.getElementById('deleteModal').style.display = 'flex';
    } else {
        if(confirm('Удалить выбранные файлы НАВСЕГДА?')) {
            apiCall('delete', s, { names: state[s].selection, force_delete: true });
        }
    }
}

function executeDelete(mode) {
    closeModal('deleteModal');
    apiCall('delete', deleteTargetSide, { 
        names: state[deleteTargetSide].selection, 
        force_delete: (mode === 'permanent') 
    });
}

function doRename(s) { const old = state[s].selection[0]; const n = prompt("Новое имя:", old); if(n) apiCall('rename', s, { old_name: old, new_name: n }); }
function doCreateObj(s, type) { const n = prompt(type === 'folder' ? "Имя папки:" : "Имя файла:"); if(n) apiCall(type === 'folder' ? 'create_folder' : 'create_file', s, { name: n }); }

async function openEditor(side, fileName, fileSize) {
    const limit = appConfig.max_edit_size || (1024 * 1024);
    if (fileSize !== undefined && fileSize > limit) {
        if (!confirm(`Размер файла (${formatSize(fileSize)}) превышает установленный лимит.\n\nВы уверены, что хотите продолжить?`)) {
            return;
        }
    }

    const res = await fetch(`api.php?action=read_file&path=${encodeURIComponent(state[side].path)}`, {
        method: 'POST',
        body: JSON.stringify({ name: fileName }) 
    });
    const data = await res.json();
    if (data.error) { showToast(data.error, 'error'); return; }
    
    document.getElementById('editorTitle').innerText = fileName; 
    document.getElementById('editorContent').value = data.content;
    const m = document.getElementById('editorModal'); 
    m.dataset.side = side; 
    m.dataset.name = fileName; 
    m.style.display = 'flex';
}

async function saveFile() { const m = document.getElementById('editorModal'); await apiCall('save_file', m.dataset.side, { name: m.dataset.name, content: document.getElementById('editorContent').value }); closeModal('editorModal'); }

function openPerms(side) {
    const name = state[side].selection[0];
    const row = document.querySelector(`#pane-${side} tr.selected`);
    const m = row.cells[3].getAttribute('title').slice(-3);
    const set = (v, r, w, x) => { document.getElementById(r).checked = v & 4; document.getElementById(w).checked = v & 2; document.getElementById(x).checked = v & 1; };
    set(m[0], 'p-u-r', 'p-u-w', 'p-u-x'); set(m[1], 'p-g-r', 'p-g-w', 'p-g-x'); set(m[2], 'p-o-r', 'p-o-w', 'p-o-x');
    updateOctal();
    const modal = document.getElementById('permsModal'); modal.dataset.side = side; modal.dataset.name = name; modal.style.display = 'flex';
}
function updateOctal() {
    const calc = (r, w, x) => (document.getElementById(r).checked?4:0)+(document.getElementById(w).checked?2:0)+(document.getElementById(x).checked?1:0);
    document.getElementById('permsOctal').innerText = `0${calc('p-u-r','p-u-w','p-u-x')}${calc('p-g-r','p-g-w','p-g-x')}${calc('p-o-r','p-o-w','p-o-x')}`;
}
async function savePerms() { const m = document.getElementById('permsModal'); await apiCall('chmod', m.dataset.side, { name: m.dataset.name, mode: document.getElementById('permsOctal').innerText }); closeModal('permsModal'); }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }
function enterFolder(s, n) { state[s].path += (state[s].path ? '/' : '') + n; loadFiles(s); }
function goUp(s) { let p = state[s].path.split('/'); p.pop(); state[s].path = p.join('/'); loadFiles(s); }
function triggerUpload(s) { document.getElementById(`file-input-${s}`).click(); }

async function handleUpload(s, i) {
    if (!i.files || i.files.length === 0) return;
    const fd = new FormData(); fd.append('file', i.files[0]);
    const res = await fetch(`api.php?action=upload&path=${encodeURIComponent(state[s].path)}`, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.confirm && confirm(data.confirm)) { fd.append('overwrite', 'true'); await fetch(`api.php?action=upload&path=${encodeURIComponent(state[s].path)}`, { method: 'POST', body: fd }); }
    else if (data.error) showToast(data.error, 'error');
    i.value = ''; loadFiles(s);
}
document.addEventListener('DOMContentLoaded', init);