<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Keep Clone with Editor.js</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

  <!-- Editor.js Core & Required Tool Bundles -->
  <script src="https://cdn.jsdelivr.net/npm/@editorjs/editorjs@2.29.0"></script>
  <script src="https://cdn.jsdelivr.net/npm/@editorjs/header@2.8.1"></script>
  <script src="https://cdn.jsdelivr.net/npm/@editorjs/code@2.9.0"></script>

  <style>
    /* 1. Force the editor's internal content wrapper to fill the modal width */
    #editorjs {
      width: 100%;
    }

    /* 2. Remove the heavy default left padding that leaves a massive blank gap */
    .ce-block__content,
    .ce-toolbar__content {
      max-width: 100% !important;
      margin: 0 !important;
    }

    /* 3. Smoothly float the plus/drag actions toolbar over to the right margin */
    .ce-toolbar__actions {
      left: auto !important;
      right: -10px !important;
    }

    /* 4. Fix tuning settings popover so it opens aligned relative to the new right-hand layout */
    .ce-settings {
      left: auto !important;
      right: 0 !important;
    }

    /* Ensure the code textarea stretches nicely to the boundaries */
    .cdx-code__textarea {
      min-height: 100px;
      font-family: monospace;
    }
  </style>
</head>
<body class="bg-gray-100 text-gray-800 font-sans">

<!-- Top Navigation / Search Bar -->
<header class="flex items-center justify-between px-6 py-3 bg-white border-b border-gray-200 sticky top-0 z-40">
  <div class="flex items-center gap-2">
    <span class="text-xl font-bold tracking-tight text-amber-500">Keep</span>
  </div>
  <div class="w-full max-w-2xl mx-4">
    <input type="text" id="search-bar" placeholder="Search notes..."
           class="w-full px-4 py-2 bg-gray-100 border border-transparent rounded-lg focus:bg-white focus:border-gray-300 focus:outline-none transition-all">
  </div>
  <div class="w-10"></div>
</header>

<main class="max-w-6xl mx-auto p-6">
  <!-- Input Trigger -->
  <div class="max-w-xl mx-auto mb-12">
    <div id="trigger-box" class="w-full bg-white rounded-xl shadow border border-gray-200 px-4 py-3 text-gray-400 cursor-pointer hover:shadow-md transition-shadow font-medium text-sm">
      Take a note...
    </div>
  </div>

  <!-- Notes Dashboard Grid Target -->
  <div id="notes-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4"></div>
</main>

<!-- Unified Modal Overlay -->
<div id="note-modal" class="fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50 hidden opacity-0 transition-opacity duration-200">
  <div class="bg-white rounded-xl shadow-2xl border border-gray-200 w-full max-w-xl mx-4 p-5 flex flex-col max-h-[85vh]">
    <input type="hidden" id="note-id" value="">

    <input type="text" id="note-title" placeholder="Title" class="w-full text-lg font-semibold mb-3 border-none outline-none placeholder-gray-400">

    <div class="overflow-y-auto flex-1 pr-2">
      <!-- Editor.js Mounting Target -->
      <div id="editorjs" class="prose max-w-none"></div>
    </div>

    <div class="mt-4 pt-3 border-t border-gray-100">
      <input type="text" id="note-tags" placeholder="Tags (comma separated)..." class="text-sm text-gray-500 border-none outline-none w-full placeholder-gray-400">
    </div>

    <div class="flex justify-end gap-2 mt-4">
      <button id="close-btn" class="px-4 py-1.5 hover:bg-gray-100 text-gray-600 font-medium text-sm rounded-md transition-colors">Close</button>
      <button id="save-btn" class="px-4 py-1.5 bg-amber-500 hover:bg-amber-600 text-white font-medium text-sm rounded-md transition-colors shadow">Save Note</button>
    </div>
  </div>
</div>

<script>
  let editor;
  let notesCache = [];

  document.addEventListener("DOMContentLoaded", () => {
    // 1. Initialize EditorJS
    editor = new EditorJS({
      inlineToolbar: true,
      autofocus: false,
      holder: 'editorjs',
      placeholder: 'Note details... (Type /code)',
      tools: {
        header: {
          class: Header,
          config: { levels: [2, 3], defaultLevel: 2 },
        },
        code: CodeTool,
      }
    });

    // 2. Global Window Interceptor (Capturing Phase)
    // This catches keys BEFORE Editor.js or its menus can steal them.
    window.addEventListener('keydown', async (event) => {
      if (event.key === 'Tab' || event.key === 'Enter') {
        const activeEditable = document.activeElement;
        if (!activeEditable || !activeEditable.hasAttribute('contenteditable')) return;

        // Strip spaces, hidden line breaks, and non-breaking spaces
        const text = activeEditable.textContent.replace(/[\n\r\s\t ]/g, "").toLowerCase();

        if (text === '/code') {
          // Instantly halt all execution paths, blocking Editor.js menu mechanics
          event.preventDefault();
          event.stopImmediatePropagation();

          // Wipe the slash command from view
          activeEditable.textContent = '';

          // Close menu interfaces
          editor.inlineToolbar.close();
          editor.toolbar.close();

          const currentIndex = editor.blocks.getCurrentBlockIndex();

          // Replace current block layout with the code layout block
          editor.blocks.replace(currentIndex, {
            type: 'code',
            data: { code: '' }
          });

          // Force target cursor inside the newly generated code block
          setTimeout(() => {
            const newBlock = editor.blocks.getBlockByIndex(currentIndex);
            const textarea = newBlock?.holder?.querySelector('textarea');
            if (textarea) {
              textarea.focus();
              textarea.value = '';
            }
          }, 50);
        }
      }
    }, true); // True forces window-level capturing phase handling

    loadNotes();

    // UI Event listeners
    document.getElementById('trigger-box').addEventListener('click', () => openModal());
    document.getElementById('close-btn').addEventListener('click', closeModal);
    document.getElementById('save-btn').addEventListener('click', triggerSave);
    document.getElementById('note-modal').addEventListener('click', (e) => {
      if(e.target === document.getElementById('note-modal')) closeModal();
    });
    document.getElementById('search-bar').addEventListener('input', (e) => loadNotes(e.target.value));
  });

  async function openModal(noteId = null) {
    const modal = document.getElementById('note-modal');
    document.getElementById('note-id').value = noteId || '';

    modal.classList.remove('hidden');
    setTimeout(() => modal.classList.remove('opacity-0'), 10);

    await editor.isReady;

    if (noteId) {
      const note = notesCache.find(n => n.id === parseInt(noteId));
      if (note) {
        document.getElementById('note-title').value = note.title;
        document.getElementById('note-tags').value = note.tags.join(', ');
        if (note.content && note.content.blocks) {
          await editor.render(note.content);
        }
      }
    } else {
      document.getElementById('note-title').value = '';
      document.getElementById('note-tags').value = '';
      editor.clear();
    }

    setTimeout(() => {
      const titleInput = document.getElementById('note-title');
      if (noteId) {
        editor.blocks.focus(editor.blocks.getBlocksCount() - 1);
      } else {
        titleInput.focus();
      }
    }, 150);
  }

  function closeModal() {
    const modal = document.getElementById('note-modal');
    modal.classList.add('opacity-0');
    setTimeout(() => modal.classList.add('hidden'), 200);
  }

  async function triggerSave() {
    try {
      const outputData = await editor.save();
      const id = document.getElementById('note-id').value;
      const title = document.getElementById('note-title').value.trim();
      const tagsRaw = document.getElementById('note-tags').value;

      if (!title && outputData.blocks.length === 0) {
        closeModal();
        return;
      }

      const tags = tagsRaw.split(',').map(t => t.trim()).filter(t => t.length > 0);

      const payload = {
        id: id ? parseInt(id) : null,
        title: title || "Untitled Note",
        content: outputData,
        tags: tags
      };

      const response = await fetch('/api/notes/save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });

      if (response.ok) {
        closeModal();
        loadNotes();
      }
    } catch (error) {
      console.error("Saving failed:", error);
    }
  }

  async function loadNotes(searchQuery = '') {
    const grid = document.getElementById('notes-grid');
    try {
      const url = searchQuery ? `/api/notes?q=${encodeURIComponent(searchQuery)}` : '/api/notes';
      const response = await fetch(url);
      notesCache = await response.json();
      grid.innerHTML = '';

      if (notesCache.length === 0) {
        grid.innerHTML = `<p class="col-span-full text-center text-gray-400 py-12">No notes found.</p>`;
        return;
      }

      notesCache.forEach(note => {
        const card = document.createElement('div');
        card.className = "bg-white p-4 rounded-xl shadow border border-gray-200 flex flex-col justify-between hover:shadow-md transition-all duration-200 cursor-pointer max-h-64 overflow-hidden";
        card.addEventListener('click', () => openModal(note.id));

        let previewHTML = '';
        if (note.content && note.content.blocks) {
          note.content.blocks.slice(0, 3).forEach(block => {
            if (block.type === 'header') {
              previewHTML += `<h3 class="font-bold my-1 text-sm text-gray-800">${block.data.text}</h3>`;
            } else if (block.type === 'code') {
              const cleanCode = block.data.code.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
              previewHTML += `<pre class="bg-gray-800 text-gray-100 p-1.5 rounded text-[11px] overflow-x-auto my-1 font-mono">${cleanCode}</pre>`;
            } else {
              previewHTML += `<p class="text-xs text-gray-500 my-0.5 line-clamp-2">${block.data.text}</p>`;
            }
          });
        }

        let tagsHTML = note.tags.map(tag => `<span class="text-[10px] bg-gray-100 px-2 py-0.5 rounded text-gray-500 font-medium">#${tag}</span>`).join(' ');

        card.innerHTML = `
                        <div>
                            <h2 class="font-semibold text-sm text-gray-900 mb-1.5">${note.title}</h2>
                            <div class="space-y-0.5 mb-3">${previewHTML}</div>
                        </div>
                        <div class="flex flex-wrap gap-1 pt-2 border-t border-gray-100">${tagsHTML}</div>
                    `;
        grid.appendChild(card);
      });
    } catch (error) {
      console.error("Error loading notes:", error);
    }
  }
</script>
</body>
</html>