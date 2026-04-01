<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Двухпанельный Файловый Менеджер</title>
    <style>
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            margin: 0; 
            padding: 15px; 
            background: #f4f4f9; 
            color: #333; 
            height: 100vh; 
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
        }
        h1 { margin-top: 0; font-size: 24px; text-align: center; }
        
        /* Двухпанельный лэйаут */
        .panes-container { 
            display: flex; 
            gap: 20px; 
            flex-grow: 1; 
            overflow: hidden; 
        }
        .pane { 
            flex: 1; 
            display: flex; 
            flex-direction: column; 
            background: white; 
            border-radius: 8px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
            padding: 15px; 
            overflow: hidden; 
        }
        
        /* Элементы управления */
        .path-bar { background: #e9ecef; padding: 10px; border-radius: 4px; margin-bottom: 10px; font-weight: bold; overflow-x: auto; white-space: nowrap; }
        .controls { display: flex; gap: 5px; margin-bottom: 10px; flex-wrap: wrap; align-items: center; }
        .controls input[type="text"] { padding: 6px; border: 1px solid #ccc; border-radius: 4px; flex-grow: 1; min-width: 100px; }
        .controls input[type="file"] { max-width: 200px; font-size: 13px; }
        .controls button { padding: 6px 12px; border: none; background: #007bff; color: white; border-radius: 4px; cursor: pointer; font-size: 13px; }
        .controls button:hover { background: #0056b3; }
        
        /* Список файлов */
        .file-list { flex-grow: 1; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px; }
        .item { display: flex; justify-content: space-between; align-items: center; padding: 8px 10px; border-bottom: 1px solid #eee; }
        .item:last-child { border-bottom: none; }
        .item:hover { background: #f8f9fa; }
        .item-name { cursor: pointer; display: flex; align-items: center; gap: 8px; flex-grow: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .item-name.dir { color: #0056b3; font-weight: bold; }
        
        /* Кнопки действий у файла */
        .actions { display: flex; gap: 5px; }
        .btn-action { padding: 4px 8px; border: none; border-radius: 3px; cursor: pointer; font-size: 12px; color: white; }
        .btn-rename { background: #ffc107; color: #333; }
        .btn-rename:hover { background: #e0a800; }
        .btn-delete { background: #dc3545; }
        .btn-delete:hover { background: #c82333; }

        /* Мобильная адаптация */
        @media (max-width: 768px) {
            body { padding: 10px; }
            .panes-container { flex-direction: column; overflow: auto; }
            .pane { flex: none; min-height: 500px; margin-bottom: 20px; }
            .controls { flex-direction: column; align-items: stretch; }
            .controls input[type="file"] { max-width: 100%; }
        }
    </style>
</head>
<body>

<h1>Web Файловый Менеджер</h1>

<div class="panes-container">
    <div class="pane" id="pane-left">
        <div class="path-bar" id="path-left">/</div>
        <div class="controls">
            <input type="file" id="file-left">
            <button onclick="uploadFile('left')">⬆ Загрузить</button>
            <input type="text" id="folder-left" placeholder="Имя папки">
            <button onclick="createFolder('left')">📁 Создать</button>
        </div>
        <div class="file-list" id="list-left"></div>
    </div>

    <div class="pane" id="pane-right">
        <div class="path-bar" id="path-right">/</div>
        <div class="controls">
            <input type="file" id="file-right">
            <button onclick="uploadFile('right')">⬆ Загрузить</button>
            <input type="text" id="folder-right" placeholder="Имя папки">
            <button onclick="createFolder('right')">📁 Создать</button>
        </div>
        <div class="file-list" id="list-right"></div>
    </div>
</div>

<script>
    // Состояние путей для обеих панелей
    const state = {
        left: { path: '' },
        right: { path: '' }
    };

    // Загрузка файлов для конкретной панели ('left' или 'right')
    async function loadFiles(side) {
        try {
            const currentPath = state[side].path;
            const res = await fetch(`api.php?action=list&path=${encodeURIComponent(currentPath)}`);
            const data = await res.json();
            
            const list = document.getElementById(`list-${side}`);
            list.innerHTML = '';

            // Кнопка назад
            if (currentPath !== '') {
                list.innerHTML += `
                    <div class="item">
                        <div class="item-name dir" onclick="goUp('${side}')">🔙 .. (Назад)</div>
                    </div>`;
            }

            if (!data.files || data.files.length === 0) {
                if (currentPath === '') list.innerHTML = '<div class="item">Папка пуста</div>';
            } else {
                data.files.forEach(f => {
                    const icon = f.isDir ? '📁' : '📄';
                    const className = f.isDir ? 'item-name dir' : 'item-name';
                    const clickAction = f.isDir ? `onclick="enterFolder('${side}', '${f.name}')"` : '';

                    list.innerHTML += `
                        <div class="item">
                            <div class="${className}" ${clickAction}>${icon} ${f.name}</div>
                            <div class="actions">
                                <button class="btn-action btn-rename" onclick="renameItem('${side}', '${f.name}')">✏️</button>
                                <button class="btn-action btn-delete" onclick="deleteItem('${side}', '${f.name}', ${f.isDir})">❌</button>
                            </div>
                        </div>
                    `;
                });
            }
            updatePathDisplay(side);
        } catch (e) {
            console.error('Ошибка загрузки:', e);
            alert('Не удалось загрузить файлы');
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

    function updatePathDisplay(side) {
        document.getElementById(`path-${side}`).innerText = `/${state[side].path}`;
    }

    // Переименование
    async function renameItem(side, oldName) {
        const newName = prompt(`Введите новое имя для "${oldName}":`, oldName);
        if (!newName || newName === oldName) return;

        const res = await fetch(`api.php?action=rename&path=${encodeURIComponent(state[side].path)}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ old_name: oldName, new_name: newName.trim() })
        });
        
        const data = await res.json();
        if (data.error) alert(data.error);
        
        // Обновляем обе панели, так как они могут смотреть в одну и ту же папку
        loadFiles('left');
        loadFiles('right');
    }

    // Создание папки
    async function createFolder(side) {
        const input = document.getElementById(`folder-${side}`);
        const name = input.value.trim();
        if (!name) return alert('Введите имя папки');
        
        const res = await fetch(`api.php?action=create_folder&path=${encodeURIComponent(state[side].path)}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name })
        });
        
        const data = await res.json();
        if (data.error) alert(data.error);

        input.value = '';
        loadFiles('left');
        loadFiles('right');
    }

    // Загрузка файла
    async function uploadFile(side) {
        const fileInput = document.getElementById(`file-${side}`);
        const file = fileInput.files[0];
        if (!file) return alert('Выберите файл для загрузки');
        
        const formData = new FormData();
        formData.append('file', file);
        
        await fetch(`api.php?action=upload&path=${encodeURIComponent(state[side].path)}`, {
            method: 'POST',
            body: formData
        });
        
        fileInput.value = '';
        loadFiles('left');
        loadFiles('right');
    }

    // Удаление
    async function deleteItem(side, name, isDir) {
        const msg = isDir ? `Удалить папку "${name}"? (Она должна быть пустой)` : `Удалить файл "${name}"?`;
        if (!confirm(msg)) return;
        
        await fetch(`api.php?action=delete&path=${encodeURIComponent(state[side].path)}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name })
        });
        
        loadFiles('left');
        loadFiles('right');
    }

    // Инициализация обеих панелей при загрузке
    loadFiles('left');
    loadFiles('right');
</script>

</body>
</html>