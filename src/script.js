const state = { left: { path: '', selection: [] }, right: { path: '', selection: [] } };

async function init() {
    const res = await fetch('api.php?action=init');
    const config = await res.json();
    document.body.setAttribute('data-theme', config.theme);
    state.left.path = config.panes.left;
    state.right.path = config.panes.right;
    loadFiles('left'); loadFiles('right');
}

async function loadFiles(side) {
    state[side].selection = [];
    updateToolbar(side);
    const res = await fetch(`api.php?action=list&path=${encodeURIComponent(state[side].path)}`);
    const data = await res.json();
    const list = document.getElementById(`list-${side}`);
    list.innerHTML = '';

    if (state[side].path !== '') {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td colspan="4" style="color:var(--accent); cursor:pointer">🔙 .. (Вверх)</td>`;
        tr.onclick = () => goUp(side);
        list.appendChild(tr);
    }

    data.files.forEach(f => {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${f.isDir?'📁':'📄'} ${f.name}</td><td>${f.owner_group}</td><td>${f.perms}</td><td>${f.modified}</td>`;
        
        tr.onclick = (e) => {
            if (e.target.tagName === 'TD' && !e.ctrlKey) {
                if (f.isDir && e.detail === 2) return enterFolder(side, f.name); // Double click
                toggleSelect(side, f.name, tr, e.ctrlKey);
            }
        };
        list.appendChild(tr);
    });
    document.getElementById(`path-${side}`).innerText = `/${state[side].path}`;
}

function toggleSelect(side, name, row, isMulti) {
    if (!isMulti) {
        state[side].selection = [name];
        Array.from(row.parentNode.children).forEach(r => r.classList.remove('selected'));
    } else {
        state[side].selection.push(name);
    }
    row.classList.add('selected');
    updateToolbar(side);
}

function updateToolbar(side) {
    const count = state[side].selection.length;
    document.querySelectorAll(`#pane-${side} .toolbar-btn.needs-sel`).forEach(btn => btn.disabled = count === 0);
    document.querySelector(`#pane-${side} .btn-rename`).disabled = count !== 1;
}

async function apiCall(action, side, body = {}) {
    try {
        const res = await fetch(`api.php?action=${action}&path=${encodeURIComponent(state[side].path)}`, {
            method: 'POST', body: JSON.stringify(body)
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        loadFiles('left'); loadFiles('right');
    } catch (e) { showToast(e.message, 'error'); }
}

function showToast(msg, type = '') {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerText = msg;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// Actions
function doCopy(side) { transfer(side, 'copy'); }
function doMove(side) { transfer(side, 'move'); }
function transfer(fromSide, type) {
    const toSide = fromSide === 'left' ? 'right' : 'left';
    apiCall('transfer', fromSide, { 
        from_path: state[fromSide].path, to_path: state[toSide].path, 
        names: state[fromSide].selection, type 
    });
}
function doDelete(side) { if(confirm('Удалить выделенное?')) apiCall('delete', side, { names: state[side].selection }); }
function doRename(side) {
    const old = state[side].selection[0];
    const res = prompt("Новое имя:", old);
    if(res) apiCall('rename', side, { old_name: old, new_name: res });
}
function doCreateFolder(side) {
    const res = prompt("Имя папки:");
    if(res) apiCall('create_folder', side, { name: res });
}

function enterFolder(side, name) { state[side].path += (state[side].path ? '/' : '') + name; loadFiles(side); }
function goUp(side) { let p = state[side].path.split('/'); p.pop(); state[side].path = p.join('/'); loadFiles(side); }
function triggerUpload(side) { document.getElementById(`file-input-${side}`).click(); }
async function handleUpload(side, input) {
    const formData = new FormData();
    formData.append('file', input.files[0]);
    await fetch(`api.php?action=upload&path=${encodeURIComponent(state[side].path)}`, { method: 'POST', body: formData });
    input.value = '';
    loadFiles(side);
}

document.addEventListener('DOMContentLoaded', init);