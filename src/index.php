<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Web Файловый Менеджер</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 20px; background: #f4f4f9; color: #333; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { margin-top: 0; }
        .controls { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .controls input[type="text"], .controls input[type="file"] { padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        .controls button { padding: 8px 15px; border: none; background: #007bff; color: white; border-radius: 4px; cursor: pointer; }
        .controls button:hover { background: #0056b3; }
        .path-bar { background: #e9ecef; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-weight: bold; }
        .file-list { border: 1px solid #ddd; border-radius: 4px; }
        .item { display: flex; justify-content: space-between; align-items: center; padding: 10px; border-bottom: 1px solid #eee; }
        .item:last-child { border-bottom: none; }
        .item:hover { background: #f8f9fa; }
        .item-name { cursor: pointer; display: flex; align-items: center; gap: 8px; flex-grow: 1; }
        .item-name.dir { color: #0056b3; font-weight: bold; }
        .btn-delete { background: #dc3545; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; font-size: 12px; }
        .btn-delete:hover { background: #c82333; }
    </style>
</head>
<body>

<div class="container">
    <h1>Файловый менеджер</h1>
    
    <div class="controls">
        <input type="file" id="fileInput">
        <button onclick="uploadFile()">Загрузить файл</button>
        
        <input type="text" id="folderName" placeholder="Имя новой папки">
        <button onclick="createFolder()">Создать папку</button>
    </div>

    <div class="path-bar" id="currentPath">/</div>
    
    <div class="file-list" id="fileList">
        </div>
</div>

<script>
    let currentPath = '';

    // Загрузка списка файлов
    async function loadFiles() {
        try {
            const res = await fetch(`api.php?action=list&path=${encodeURIComponent(currentPath)}`);
            const data = await res.json();
            
            const list = document.getElementById('fileList');
            list.innerHTML = '';

            // Кнопка "Назад" если мы не в корне
            if (currentPath !== '') {
                list.innerHTML += `
                    <div class="item">
                        <div class="item-name dir" onclick="goUp()">📁 .. (Назад)</div>
                    </div>`;
            }

            if (!data.files || data.files.length === 0) {
                if (currentPath === '') list.innerHTML = '<div class="item">Папка пуста</div>';
            } else {
                data.files.forEach(f => {
                    const icon = f.isDir ? '📁' : '📄';
                    const className = f.isDir ? 'item-name dir' : 'item-name';
                    const clickAction = f.isDir ? `onclick="enterFolder('${f.name}')"` : '';

                    list.innerHTML += `
                        <div class="item">
                            <div class="${className}" ${clickAction}>${icon} ${f.name}</div>
                            <button class="btn-delete" onclick="deleteItem('${f.name}', ${f.isDir})">Удалить</button>
                        </div>
                    `;
                });
            }
            updatePathDisplay();
        } catch (e) {
            console.error('Ошибка загрузки:', e);
            alert('Не удалось загрузить файлы');
        }
    }

    // Навигация
    function enterFolder(name) {
        currentPath += (currentPath ? '/' : '') + name;
        loadFiles();
    }

    function goUp() {
        let parts = currentPath.split('/');
        parts.pop();
        currentPath = parts.join('/');
        loadFiles();
    }

    function updatePathDisplay() {
        document.getElementById('currentPath').innerText = `Текущая папка: /${currentPath}`;
    }

    // Создание папки
    async function createFolder() {
        const name = document.getElementById('folderName').value.trim();
        if (!name) return alert('Введите имя папки');
        
        await fetch(`api.php?action=create_folder&path=${encodeURIComponent(currentPath)}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name })
        });
        
        document.getElementById('folderName').value = '';
        loadFiles();
    }

    // Загрузка файла
    async function uploadFile() {
        const fileInput = document.getElementById('fileInput');
        const file = fileInput.files[0];
        if (!file) return alert('Выберите файл для загрузки');
        
        const formData = new FormData();
        formData.append('file', file);
        
        await fetch(`api.php?action=upload&path=${encodeURIComponent(currentPath)}`, {
            method: 'POST',
            body: formData
        });
        
        fileInput.value = '';
        loadFiles();
    }

    // Удаление
    async function deleteItem(name, isDir) {
        const msg = isDir ? `Удалить папку "${name}"? (Она должна быть пустой)` : `Удалить файл "${name}"?`;
        if (!confirm(msg)) return;
        
        await fetch(`api.php?action=delete&path=${encodeURIComponent(currentPath)}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name })
        });
        
        loadFiles();
    }

    // Инициализация
    loadFiles();
</script>

</body>
</html>