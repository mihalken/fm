const state = { 
    left: { path: '' }, 
    right: { path: '' } 
};

// Загрузка настроек при старте
async function init() {
    try {
        const res = await fetch('api.php?action=init');
        const config = await res.json();
        
        // Установка темы
        document.body.setAttribute('data-theme', config.theme || 'light');
        
        // Применение путей по умолчанию
        state.left.path = config.panes.left;
        state.right.path = config.panes.right;
        
        loadFiles('left');
        loadFiles('right');
    } catch (e) {
        console.error('Ошибка инициализации:', e);
    }
}

// Загрузка списка файлов
async function loadFiles(side) {
    const currentPath = state[side].path;
    try {
        const res = await fetch(`api.php?action=list&path=${encodeURIComponent(currentPath)}`);
        const data = await res.json();
        const list = document.getElementById(`list-${side}`);
        list.innerHTML = '';

        // Кнопка перехода на уровень вверх
        if (currentPath !== '') {
            list.innerHTML += `
                <tr>
                    <td colspan="5" class="col-name dir" onclick="goUp('${side}')">📁 .. (Назад)</td>
                </tr>`;
        }

        if (data.files) {
            data.files.forEach(f => {
                const icon = f.isDir ? '📁' : '📄';
                const clickAction = f.isDir ? `onclick="enterFolder('${side}','${f.name}')"` : '';
                
                list.innerHTML += `
                    <tr>
                        <td class="col-name ${f.isDir ? 'dir' : ''}" ${clickAction}>${icon} ${f.name}</td>
                        <td class="col-meta">${f.owner}</td>
                        <td class="col-meta" style="cursor:pointer; text-decoration:underline" onclick="changePerms('${side}','${f.name}','${f.perms}')">${f.perms}</td>
                        <td class="col-meta">${f.modified}</td>
                        <td class="col-actions">
                            <button onclick="renameItem('${side}','${f.name}')" title="Переименовать">✏️</button>
                            <button onclick="deleteItem('${side}','${f.name}')" title="Удалить">❌</button>
                        </td>
                    </tr>`;
            });
        }
        document.getElementById(`path-${side}`).innerText = `/${currentPath}`;
    } catch (e) {
        console.error('Ошибка загрузки файлов:', e);
    }
}

// Навигация
function enterFolder(side, name) {
    state[side].path += (state[side].path ? '/' : '') + name;
    loadFiles(side);
}

function goUp(side) {
    let parts = state[side].path.split('/');
    parts.pop();
    state[side].path = parts.join('/');
    loadFiles(side);
}

// Операции: Создание папки
async function createFolder(side) {
    const input = document.getElementById(`folder-${side}`);
    const name = input.value.trim();
    if (!name) return alert('Введите имя папки');
    
    await fetch(`api.php?action=create_folder&path=${encodeURIComponent(state[side].path)}`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ name })
    });
    
    input.value = '';
    loadFiles('left'); loadFiles('right');
}

// Загрузка файлов
async function uploadFile(side) {
    const fileInput = document.getElementById(`file-${side}`);
    const file = fileInput.files[0];
    if (!file) return alert('Выберите файл');
    
    const formData = new FormData();
    formData.append('file', file);
    
    await fetch(`api.php?action=upload&path=${encodeURIComponent(state[side].path)}`, {
        method: 'POST',
        body: formData
    });
    
    fileInput.value = '';
    loadFiles('left'); loadFiles('right');
}

// Переименование
async function renameItem(side, oldName) {
    const newName = prompt(`Новое имя для "${oldName}":`, oldName);
    if (!newName || newName === oldName) return;

    await fetch(`api.php?action=rename&path=${encodeURIComponent(state[side].path)}`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ old_name: oldName, new_name: newName.trim() })
    });
    
    loadFiles('left'); loadFiles('right');
}

// Изменение прав доступа (chmod)
async function changePerms(side, name, current) {
    const mode = prompt(`Права для ${name} (например, 0755 или 0644):`, current);
    if (!mode || mode === current) return;

    const res = await fetch(`api.php?action=chmod&path=${encodeURIComponent(state[side].path)}`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ name, mode })
    });
    
    const data = await res.json();
    if (data.error) alert(data.error);
    
    loadFiles(side);
}

// Удаление
async function deleteItem(side, name) {
    if (!confirm(`Удалить "${name}"?`)) return;
    
    await fetch(`api.php?action=delete&path=${encodeURIComponent(state[side].path)}`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ name })
    });
    
    loadFiles('left'); loadFiles('right');
}

// Запуск при загрузке страницы
document.addEventListener('DOMContentLoaded', init);