let appConfig = {};
let tasksMinimized = false;
const state = { 
    left: { path: '', selection: [], files: [], renderedFiles: [], lastClickIndex: -1 }, 
    right: { path: '', selection: [], files: [], renderedFiles: [], lastClickIndex: -1 } 
};
let clipboard = { type: null, files: [], sourcePath: '' };
let lastCleanupData = null; 
let sizeFormat = 'human'; 
let deleteTargetSide = null; 
let activeSide = 'left';

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
    renderList('left', { files: state.left.files });
    renderList('right', { files: state.right.files });
}

async function calcFolderSize(side, td, e) {
    e.stopPropagation();
    const row = td.parentNode;
    const name = row.getAttribute('data-name');
    
    td.innerText = '⏳...';
    td.onclick = null; 
    td.style.cursor = 'default';
    
    try {
        const res = await fetch(`api.php?action=get_folder_size&path=${encodeURIComponent(state[side].path)}`, {
            method: 'POST', body: JSON.stringify({ name: name })
        });
        const data = await res.json();
        
        if (data.error) {
            showToast(data.error, 'error');
            td.innerText = '-';
            td.onclick = (event) => calcFolderSize(side, td, event);
            td.style.cursor = 'pointer';
        } else {
            const fileObj = state[side].files.find(f => f.name === name);
            if (fileObj) {
                fileObj.size = data.size;
                fileObj.sizeCalculated = true;
            }
            td.innerText = formatSize(data.size);
            td.onclick = (event) => toggleSizeFormat(event);
            td.style.cursor = 'pointer';
            td.title = 'Сменить формат (байты/МБ)';
        }
    } catch (err) {
        showToast('Ошибка вычисления размера', 'error');
        td.innerText = '-';
        td.onclick = (event) => calcFolderSize(side, td, event);
        td.style.cursor = 'pointer';
    }
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
    
    if (appConfig.ffprobe_enabled) {
        document.querySelectorAll('.tool-menu-container').forEach(c => c.style.display = ''); 
        document.querySelectorAll('.tool-ffprobe').forEach(b => b.style.display = 'block');
    }
    
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
    state[side].lastClickIndex = -1; 
    
    if (data.error) { showToast(data.error, 'error'); updateToolbar(side); return; }
    if (data.files) state[side].files = data.files;
    
    const list = document.getElementById(`list-${side}`);
    list.innerHTML = '';
    
    if (state[side].path !== '') {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td colspan="6" style="color:var(--accent); cursor:pointer">🔙 .. Назад</td>`;
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

    state[side].renderedFiles = filesToRender.map(f => f.name);

    filesToRender.forEach((f, index) => {
        const tr = document.createElement('tr');
        tr.setAttribute('data-name', f.name); 
        
        if (oldSel.includes(f.name)) { state[side].selection.push(f.name); tr.classList.add('selected'); }
        if (clipboard.type === 'cut' && clipboard.sourcePath === state[side].path && clipboard.files.includes(f.name)) tr.classList.add('clipboard-cut');
        
        const sizeHTML = (f.isDir && !f.sizeCalculated) 
            ? `<td class="clickable-size" onclick="calcFolderSize('${side}', this, event)" title="Нажмите, чтобы подсчитать размер">-</td>`
            : `<td class="clickable-size" onclick="toggleSizeFormat(event)" title="Сменить формат">${formatSize(f.size)}</td>`;

        tr.innerHTML = `
            <td title="${f.name}">${f.isDir?'📁':'📄'} ${f.isLink?'🔗 ':''}${f.name}</td>
            <td>${f.ext}</td>
            ${sizeHTML}
            <td>${f.owner_group}</td>
            <td title="${f.perms}" style="font-family:monospace">${formatPerms(f.perms)}</td>
            <td>${f.modified}</td>
        `;
        
        tr.onclick = (e) => toggleSelect(side, f.name, index, e); 
        
        // Умный двойной клик (Свойства или Редактор)
        tr.ondblclick = async () => {
            if (f.isDir) {
                enterFolder(side, f.name);
            } else {
                const res = await fetch(`api.php?action=get_file_info&path=${encodeURIComponent(state[side].path)}`, {
                    method: 'POST', body: JSON.stringify({ name: f.name })
                });
                const data = await res.json();
                if (data.info && data.info.is_text) {
                    openEditor(side, f.name, f.size);
                } else {
                    openFileInfo(side, f.name);
                }
            }
        };
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

function toggleSelect(side, name, index, e) {
    const ctrl = e.ctrlKey || e.metaKey;
    const shift = e.shiftKey;

    if (shift) document.getSelection().removeAllRanges();

    if (shift && state[side].lastClickIndex !== -1) {
        const start = Math.min(state[side].lastClickIndex, index);
        const end = Math.max(state[side].lastClickIndex, index);
        const rangeNames = state[side].renderedFiles.slice(start, end + 1);
        
        if (ctrl) {
            state[side].selection = [...new Set([...state[side].selection, ...rangeNames])];
        } else {
            state[side].selection = rangeNames;
        }
    } else {
        if (ctrl) {
            state[side].selection.includes(name) 
                ? state[side].selection = state[side].selection.filter(n => n !== name) 
                : state[side].selection.push(name);
        } else {
            state[side].selection = [name];
        }
        state[side].lastClickIndex = index;
    }

    const list = document.getElementById(`list-${side}`);
    Array.from(list.children).forEach(row => {
        const rowName = row.getAttribute('data-name');
        if (rowName) {
            row.classList.toggle('selected', state[side].selection.includes(rowName));
        }
    });
    
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

function toggleTasks() {
    tasksMinimized = !tasksMinimized;
    const widget = document.getElementById('tasks-widget');
    const chevron = document.getElementById('task-chevron');
    if (tasksMinimized) {
        widget.classList.add('minimized');
        chevron.innerText = '▲';
    } else {
        widget.classList.remove('minimized');
        chevron.innerText = '▼';
    }
}

async function pollTasks() {
    const res = await fetch('api.php?action=poll_tasks');
    const tasks = await res.json();
    const widget = document.getElementById('tasks-widget');
    const container = document.getElementById('tasks-container');
    const countSpan = document.getElementById('task-count');
    const btnClear = document.getElementById('btn-clear-tasks');
    
    const taskList = Object.values(tasks);
    
    if (taskList.length === 0) {
        if (widget.style.display === 'flex') {
            reloadBoth(); 
            if (lastCleanupData) {
                fetch('api.php?action=cleanup_dirs', { method: 'POST', body: JSON.stringify(lastCleanupData) });
                lastCleanupData = null;
            }
        }
        widget.style.display = 'none'; 
        container.innerHTML = ''; 
        return; 
    }
    
    widget.style.display = 'flex';
    countSpan.innerText = taskList.length;

    const hasDoneTasks = taskList.some(t => ['completed', 'cancelled', 'error'].includes(t.status));
    if (btnClear) btnClear.style.display = hasDoneTasks ? 'block' : 'none';

    const currentDomIds = Array.from(container.children).map(el => el.dataset.taskId);
    const activeTaskIds = taskList.map(t => t.id);

    currentDomIds.forEach(id => {
        if (!activeTaskIds.includes(id)) {
            const el = document.getElementById(`task-card-${id}`);
            if (el) el.remove();
        }
    });

    taskList.forEach(t => {
        const isNative = (t.native === true) && (t.status === 'running');
        const percent = t.size === 0 ? 100 : Math.round((t.offset / t.size) * 100);
        
        const displayPercent = isNative ? 'ОС...' : `${percent}%`;
        const widthPercent = isNative ? 100 : percent;
        
        let statusIcon = '🔄';
        if (t.status === 'completed') statusIcon = '✅';
        else if (t.status === 'cancelled') statusIcon = '🚫';
        else if (t.status === 'error') statusIcon = '⚠️';
        else if (t.status === 'paused') statusIcon = '⏸️';

        const isDone = ['completed', 'cancelled', 'error'].includes(t.status);
        const bgColor = (t.status === 'error' || t.status === 'cancelled') ? '#dc3545' : (t.status === 'completed' ? '#28a745' : 'var(--accent)');

        let card = document.getElementById(`task-card-${t.id}`);

        if (!card) {
            card = document.createElement('div');
            card.className = 'task-card';
            card.id = `task-card-${t.id}`;
            card.dataset.taskId = t.id;
            card.innerHTML = `
                <div class="task-header">
                    <span class="task-title"></span>
                    <div class="task-controls"></div>
                </div>
                <div class="task-progress-bg">
                    <div class="task-progress-fill"></div>
                </div>
            `;
            container.appendChild(card);
        }

        const titleSpan = card.querySelector('.task-title');
        const controlsDiv = card.querySelector('.task-controls');
        const progressFill = card.querySelector('.task-progress-fill');

        const titleText = `${statusIcon} ${t.type==='copy'?'Копия':'Перенос'}: ${t.name.split('/').pop()} (${displayPercent})`;
        if (titleSpan.innerText !== titleText) {
            titleSpan.innerText = titleText;
            titleSpan.title = t.name + (isDone ? ' (Нажмите, чтобы убрать)' : '');
            if (isDone) {
                titleSpan.style.cursor = 'pointer';
                titleSpan.onclick = () => clearTask(t.id);
            } else {
                titleSpan.style.cursor = 'default';
                titleSpan.onclick = null;
            }
        }

        let controlsHTML = '';
        if (!isDone) {
            if (!isNative) {
                controlsHTML += `<button class="task-btn" onclick="controlTask('${t.id}', '${t.status === 'paused' ? 'running' : 'paused'}')" title="Пауза">⏸️</button>`;
                controlsHTML += `<button class="task-btn" style="color:#dc3545" onclick="controlTask('${t.id}', 'cancel')" title="Отмена">✖</button>`;
            }
        }

        if (controlsDiv.innerHTML !== controlsHTML) {
            controlsDiv.innerHTML = controlsHTML;
        }

        progressFill.style.width = `${widthPercent}%`;
        progressFill.style.backgroundColor = bgColor;

        if (isNative) {
            if (!progressFill.classList.contains('native-progress')) {
                progressFill.classList.add('native-progress');
            }
        } else {
            if (progressFill.classList.contains('native-progress')) {
                progressFill.classList.remove('native-progress');
            }
        }
    });
}

async function controlTask(id, cmd) {
    await fetch('api.php?action=control_task', { method: 'POST', body: JSON.stringify({id, cmd}) });
    pollTasks(); 
}

async function clearTask(id) {
    await fetch('api.php?action=clear_task', { method: 'POST', body: JSON.stringify({id}) });
    pollTasks();
}

async function clearCompletedTasks(e) {
    e.stopPropagation();
    await fetch('api.php?action=clear_completed', { method: 'POST', body: JSON.stringify({}) });
    pollTasks(); 
}

function updateToolbar(side) {
    const count = state[side].selection.length;
    const pane = document.getElementById(`pane-${side}`);
    if (!pane) return;
    
    // Стандартные кнопки
    pane.querySelectorAll('.needs-sel').forEach(b => b.disabled = count === 0);
    
    pane.querySelectorAll('.needs-one').forEach(b => {
        b.disabled = count !== 1;
        // Блокировка самого выпадающего меню "Инструменты" (если выделено 0 или >1 файлов)
        if (b.classList.contains('dropdown-btn')) {
            const parent = b.closest('.dropdown');
            if (parent) {
                if (count !== 1) parent.classList.add('disabled');
                else parent.classList.remove('disabled');
            }
        }
    });
    
    document.querySelectorAll('.btn-paste').forEach(b => b.disabled = !clipboard.type);

    // --- УМНОЕ МЕНЮ ИНСТРУМЕНТОВ ---
    let isMedia = false;
    
    // Проверяем, является ли выделенный объект медиа-файлом
    if (count === 1) {
        const fileName = state[side].selection[0];
        const fileObj = state[side].files.find(f => f.name === fileName);
        if (fileObj && !fileObj.isDir) {
            const mediaExts = ['mp4', 'mkv', 'avi', 'mov', 'webm', 'mp3', 'wav', 'flac', 'ogg', 'm4a', 'aac', 'ts', 'flv', 'wmv'];
            if (mediaExts.includes(fileObj.ext.toLowerCase())) {
                isMedia = true;
            }
        }
    }

    // Делаем пункт ffprobe неактивным, если это не медиа-файл
    if (appConfig.ffprobe_enabled) {
        const ffprobeBtn = pane.querySelector('.tool-ffprobe');
        if (ffprobeBtn) {
            ffprobeBtn.disabled = !isMedia;
            if (!isMedia) {
                ffprobeBtn.style.opacity = '0.4';
                ffprobeBtn.style.cursor = 'not-allowed';
            } else {
                ffprobeBtn.style.opacity = '1';
                ffprobeBtn.style.cursor = 'pointer';
            }
        }
    }
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
function doShowInfo(s) { const name = state[s].selection[0]; if(name) openFileInfo(s, name); }

async function openFileInfo(side, fileName) {
    const res = await fetch(`api.php?action=get_file_info&path=${encodeURIComponent(state[side].path)}`, {
        method: 'POST', body: JSON.stringify({ name: fileName })
    });
    const data = await res.json();
    
    if (data.error) { showToast(data.error, 'error'); return; }
    
    const info = data.info;
    document.getElementById('infoTitle').innerText = info.name;
    
    let html = `<table class="info-table">`;
    const addRow = (label, val) => { html += `<tr><td>${label}:</td><td>${val}</td></tr>`; };

    addRow('Тип', info.type);
    if (info.mime && info.mime !== 'Неизвестно') addRow('MIME-тип', info.mime);
    if (info.os_info) addRow('ОС детект', info.os_info);
    
    html += `<tr><td colspan="2"><hr style="border:0; border-top:1px solid var(--border-color); margin:4px 0;"></td></tr>`;
    
    addRow('Размер', formatSize(info.size) + ` <span style="font-size:11px; opacity:0.6;">(${info.size} байт)</span>`);
    addRow('Изменен', info.modified);
    addRow('Создан', info.created);
    addRow('Открыт', info.accessed);
    
    html += `<tr><td colspan="2"><hr style="border:0; border-top:1px solid var(--border-color); margin:4px 0;"></td></tr>`;
    
    addRow('Владелец', info.owner);
    addRow('Группа', info.group);
    addRow('Права', `<span style="font-family:monospace;">${info.perms}</span>`);
    
    let accessFlags = [];
    if (info.is_readable) accessFlags.push('Чтение');
    if (info.is_writable) accessFlags.push('Запись');
    if (info.is_executable) accessFlags.push('Выполнение');
    addRow('Доступ', accessFlags.join(', ') || 'Нет');
    
    addRow('Путь', `<span style="font-family:monospace; font-size:11px;">${info.path}</span>`);

    html += `</table>`;
    
    document.getElementById('infoContent').innerHTML = html;
    document.getElementById('infoModal').style.display = 'flex';
}

function parseFrac(f) {
    if (!f) return 0;
    const p = f.split('/');
    return p.length === 2 ? parseInt(p[0]) / parseInt(p[1]) : parseFloat(f);
}

async function doFfprobe(side) {
    const name = state[side].selection[0];
    if (!name) return;
    
    showToast('Анализ медиафайла...', 'info');
    
    const res = await fetch(`api.php?action=ffprobe&path=${encodeURIComponent(state[side].path)}`, {
        method: 'POST', body: JSON.stringify({ name: name })
    });
    const data = await res.json();
    if (data.error) { showToast(data.error, 'error'); return; }
    
    document.getElementById('ffprobeTitle').innerText = data.name;
    
    let html = '';
    const format = data.ffprobe.format;
    if (format) {
        html += `<div style="margin-bottom:8px; font-weight:bold; color:var(--accent);">Контейнер</div>`;
        html += `<table class="info-table">`;
        html += `<tr><td>Формат</td><td>${format.format_name}</td></tr>`;
        if (format.duration) html += `<tr><td>Длительность</td><td>${parseFloat(format.duration).toFixed(2)} сек</td></tr>`;
        if (format.bit_rate) html += `<tr><td>Битрейт</td><td>${Math.round(format.bit_rate / 1024)} kbps</td></tr>`;
        if (format.size) html += `<tr><td>Размер</td><td>${formatSize(format.size)}</td></tr>`;
        html += `</table>`;
    }
    
    if (data.ffprobe.streams && data.ffprobe.streams.length > 0) {
        data.ffprobe.streams.forEach((s, i) => {
            const typeName = s.codec_type === 'video' ? '🎥 Видео' : (s.codec_type === 'audio' ? '🎵 Аудио' : '🎞️ Поток');
            html += `<div style="margin-bottom:8px; font-weight:bold; color:var(--accent); margin-top:15px;">${typeName} #${i}</div>`;
            html += `<table class="info-table">`;
            html += `<tr><td>Кодек</td><td>${s.codec_name} <span style="opacity:0.6">(${s.codec_long_name})</span></td></tr>`;
            
            if (s.codec_type === 'video') {
                html += `<tr><td>Разрешение</td><td>${s.width}x${s.height}</td></tr>`;
                const fps = parseFrac(s.r_frame_rate);
                if (fps) html += `<tr><td>FPS</td><td>${fps.toFixed(2)}</td></tr>`;
                if (s.bit_rate) html += `<tr><td>Битрейт</td><td>${Math.round(s.bit_rate / 1024)} kbps</td></tr>`;
            }
            if (s.codec_type === 'audio') {
                html += `<tr><td>Частота</td><td>${s.sample_rate} Hz</td></tr>`;
                html += `<tr><td>Каналы</td><td>${s.channels}</td></tr>`;
                if (s.bit_rate) html += `<tr><td>Битрейт</td><td>${Math.round(s.bit_rate / 1024)} kbps</td></tr>`;
            }
            html += `</table>`;
        });
    } else if (!format) {
        html = `<p style="color:#dc3545;">Медиаданные не найдены.</p>`;
    }
    
    document.getElementById('ffprobeContent').innerHTML = html;
    document.getElementById('ffprobeModal').style.display = 'flex';
}

async function openEditor(side, fileName, fileSize) {
    const limit = appConfig.max_edit_size || (1024 * 1024);
    if (fileSize !== undefined && fileSize > limit) {
        if (!confirm(`Размер файла (${formatSize(fileSize)}) превышает установленный лимит.\n\nВы уверены, что хотите продолжить?`)) {
            return;
        }
    }

    const res = await fetch(`api.php?action=read_file&path=${encodeURIComponent(state[side].path)}`, {
        method: 'POST', body: JSON.stringify({ name: fileName }) 
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
    const m = row.cells[4].getAttribute('title').slice(-3);
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

document.addEventListener('mousedown', (e) => {
    if (e.target.closest('#pane-left')) activeSide = 'left';
    else if (e.target.closest('#pane-right')) activeSide = 'right';
});

document.addEventListener('keydown', (e) => {
    if (['INPUT', 'TEXTAREA'].includes(e.target.tagName) || e.target.isContentEditable) return;
    const anyModalOpen = Array.from(document.querySelectorAll('.modal-overlay')).some(m => m.style.display === 'flex');
    if (anyModalOpen) return;

    const isCtrl = e.ctrlKey || e.metaKey;

    if (isCtrl && e.code === 'KeyC') {
        if (state[activeSide].selection.length > 0) {
            e.preventDefault();
            addToClipboard(activeSide, 'copy');
        }
    } else if (isCtrl && e.code === 'KeyX') {
        if (state[activeSide].selection.length > 0) {
            e.preventDefault();
            addToClipboard(activeSide, 'cut');
        }
    } else if (isCtrl && e.code === 'KeyV') {
        if (clipboard.type) {
            e.preventDefault();
            doPaste(activeSide);
        }
    }
});

document.addEventListener('DOMContentLoaded', init);

document.addEventListener('DOMContentLoaded', () => {
    let splitRatio = 50; 
    try { 
        const savedSplit = localStorage.getItem('cc_split'); 
        if (savedSplit) splitRatio = parseFloat(savedSplit); 
    } catch(e) {}

    function applySplit(ratio) {
        if (window.innerWidth <= 768) return; 
        const leftPane = document.getElementById('pane-left');
        const rightPane = document.getElementById('pane-right');
        if (leftPane && rightPane) {
            leftPane.style.width = `calc(${ratio}% - 2px)`; 
            leftPane.style.flex = 'none'; 
            rightPane.style.flex = '1';   
        }
    }

    applySplit(splitRatio);
    window.addEventListener('resize', () => applySplit(splitRatio));

    const resizer = document.getElementById('resizer');
    let isResizing = false;

    if (resizer) {
        resizer.addEventListener('mousedown', (e) => {
            isResizing = true;
            resizer.classList.add('active');
            document.body.style.cursor = 'col-resize';
            document.body.style.userSelect = 'none'; 
        });

        document.addEventListener('mousemove', (e) => {
            if (!isResizing) return;
            let newRatio = (e.clientX / window.innerWidth) * 100;
            if (newRatio < 10) newRatio = 10;
            if (newRatio > 90) newRatio = 90;
            
            splitRatio = newRatio;
            applySplit(splitRatio);
        });

        document.addEventListener('mouseup', () => {
            if (isResizing) {
                isResizing = false;
                resizer.classList.remove('active');
                document.body.style.cursor = '';
                document.body.style.userSelect = '';
                try { localStorage.setItem('cc_split', splitRatio); } catch(e) {}
            }
        });
    }
});