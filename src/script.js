const state = { left: { path: '' }, right: { path: '' } };

async function init() {
    const res = await fetch('api.php?action=init');
    const config = await res.json();
    document.body.setAttribute('data-theme', config.theme);
    state.left.path = config.panes.left;
    state.right.path = config.panes.right;
    loadFiles('left'); loadFiles('right');
}

async function loadFiles(side) {
    const res = await fetch(`api.php?action=list&path=${encodeURIComponent(state[side].path)}`);
    const data = await res.json();
    const list = document.getElementById(`list-${side}`);
    list.innerHTML = '';

    if (state[side].path !== '') {
        list.innerHTML += `<tr><td colspan="5" class="col-name" onclick="goUp('${side}')">🔙 ..</td></tr>`;
    }

    data.files.forEach(f => {
        list.innerHTML += `
            <tr>
                <td class="col-name" onclick="${f.isDir ? `enterFolder('${side}','${f.name}')` : ''}">${f.isDir?'📁':'📄'} ${f.name}</td>
                <td class="col-meta">${f.owner_group}</td>
                <td class="col-meta" onclick="changePerms('${side}','${f.name}','${f.perms}')">${f.perms}</td>
                <td class="col-meta">${f.modified}</td>
                <td class="col-actions">
                    <button class="action-btn" onclick="transfer('${side}','${f.name}',${f.isDir},'copy')" title="Копировать в соседнюю панель">📋</button>
                    <button class="action-btn" onclick="transfer('${side}','${f.name}',${f.isDir},'move')" title="Переместить в соседнюю панель">🚚</button>
                    <button class="action-btn" onclick="renameItem('${side}','${f.name}')">✏️</button>
                    <button class="action-btn" onclick="deleteItem('${side}','${f.name}')">🗑️</button>
                </td>
            </tr>`;
    });
    document.getElementById(`path-${side}`).innerText = `/${state[side].path}`;
}

async function transfer(fromSide, name, isDir, type) {
    const toSide = fromSide === 'left' ? 'right' : 'left';
    await fetch(`api.php?action=transfer`, {
        method: 'POST',
        body: JSON.stringify({
            from_path: state[fromSide].path,
            to_path: state[toSide].path,
            name, isDir, type
        })
    });
    loadFiles('left'); loadFiles('right');
}

function enterFolder(side, name) { state[side].path += (state[side].path ? '/' : '') + name; loadFiles(side); }
function goUp(side) { let p = state[side].path.split('/'); p.pop(); state[side].path = p.join('/'); loadFiles(side); }

function triggerUpload(side) { document.getElementById(`file-input-${side}`).click(); }
async function handleUpload(side, input) {
    const formData = new FormData();
    formData.append('file', input.files[0]);
    await fetch(`api.php?action=upload&path=${encodeURIComponent(state[side].path)}`, { method: 'POST', body: formData });
    loadFiles(side);
}

async function createFolder(side) {
    const name = prompt("Имя папки:");
    if (name) {
        await fetch(`api.php?action=create_folder&path=${encodeURIComponent(state[side].path)}`, {
            method: 'POST', body: JSON.stringify({ name })
        });
        loadFiles(side);
    }
}

async function renameItem(side, oldName) {
    const newName = prompt("Новое имя:", oldName);
    if (newName && newName !== oldName) {
        await fetch(`api.php?action=rename&path=${encodeURIComponent(state[side].path)}`, {
            method: 'POST', body: JSON.stringify({ old_name: oldName, new_name: newName })
        });
        loadFiles('left'); loadFiles('right');
    }
}

async function deleteItem(side, name) {
    if (confirm(`Удалить ${name}?`)) {
        await fetch(`api.php?action=delete&path=${encodeURIComponent(state[side].path)}`, {
            method: 'POST', body: JSON.stringify({ name })
        });
        loadFiles('left'); loadFiles('right');
    }
}

async function changePerms(side, name, cur) {
    const mode = prompt("Права (октально):", cur);
    if (mode) {
        await fetch(`api.php?action=chmod&path=${encodeURIComponent(state[side].path)}`, {
            method: 'POST', body: JSON.stringify({ name, mode })
        });
        loadFiles(side);
    }
}

document.addEventListener('DOMContentLoaded', init);