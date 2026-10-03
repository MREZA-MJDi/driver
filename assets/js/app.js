(() => {
    "use strict";

    const API = "../api/files.php";
    const SHARE_API = "../api/share.php";
    const CHUNK_SIZE = 8 * 1024 * 1024;
    const MAX_FILE_SIZE = 10 * 1024 * 1024 * 1024;
    const RESUME_KEY = "personal-drive-uploads";

    const uploadButton = document.getElementById("uploadButton");
    const browseButton = document.getElementById("browseButton");
    const fileInput = document.getElementById("fileInput");
    const uploadZone = document.getElementById("uploadZone");
    const uploadStatus = document.getElementById("uploadStatus");

    const searchInput = document.getElementById("searchInput");
    const filesGrid = document.getElementById("filesGrid");
    const fileCount = document.getElementById("fileCount");
    const emptyState = document.getElementById("emptyState");

    const storageUsed = document.getElementById("storageUsed");
    const storageAvailable = document.getElementById("storageAvailable");
    const storageProgress = document.getElementById("storageProgress");

    const mobileMenu = document.getElementById("mobileMenu");
    const sidebar = document.getElementById("sidebar");
    const mobileOverlay = document.getElementById("mobileOverlay");

    const shareModal = document.getElementById("shareModal");
    const modalClose = document.getElementById("modalClose");
    const modalFileName = document.getElementById("modalFileName");
    const shareLink = document.getElementById("shareLink");
    const copyLink = document.getElementById("copyLink");

    const toastContainer = document.getElementById("toastContainer");

    let files = [];
    let activeUpload = null;

    function showToast(message) {
        const toast = document.createElement("div");
        toast.className = "toast";
        toast.textContent = message;
        toastContainer.appendChild(toast);

        window.setTimeout(() => {
            toast.style.opacity = "0";
            toast.style.transform = "translateY(8px)";
            window.setTimeout(() => toast.remove(), 180);
        }, 2800);
    }

    function formatFileSize(bytes) {
        if (!Number.isFinite(bytes) || bytes <= 0) return "0 B";

        const units = ["B", "KB", "MB", "GB", "TB"];
        const index = Math.min(
            units.length - 1,
            Math.floor(Math.log(bytes) / Math.log(1024))
        );
        const value = bytes / Math.pow(1024, index);

        return `${value.toFixed(value >= 10 || index === 0 ? 0 : 1)} ${units[index]}`;
    }

    function formatDate(value) {
        const date = new Date(value);

        if (Number.isNaN(date.getTime())) {
            return "Recently";
        }

        return new Intl.DateTimeFormat(undefined, {
            month: "short",
            day: "numeric",
        }).format(date);
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll("&", "&amp;")
            .replaceAll("<", "&lt;")
            .replaceAll(">", "&gt;")
            .replaceAll('"', "&quot;")
            .replaceAll("'", "&#039;");
    }

    function getFileType(file) {
        const mime = file.mime_type || "";
        const extension = (file.original_name || "")
            .split(".")
            .pop()
            .toLowerCase();

        if (mime.startsWith("video/") || ["mp4", "mov", "mkv", "webm", "avi"].includes(extension)) {
            return "video";
        }

        if (mime === "application/pdf" || extension === "pdf") {
            return "document";
        }

        if (["zip", "rar", "7z", "tar", "gz"].includes(extension)) {
            return "archive";
        }

        return "file";
    }

    function getResumeMap() {
        try {
            return JSON.parse(localStorage.getItem(RESUME_KEY) || "{}");
        } catch {
            return {};
        }
    }

    function saveResume(file, uploadId) {
        const map = getResumeMap();
        map[`${file.name}:${file.size}:${file.lastModified}`] = uploadId;
        localStorage.setItem(RESUME_KEY, JSON.stringify(map));
    }

    function removeResume(file) {
        const map = getResumeMap();
        delete map[`${file.name}:${file.size}:${file.lastModified}`];
        localStorage.setItem(RESUME_KEY, JSON.stringify(map));
    }

    async function request(url, options = {}) {
        const response = await fetch(url, {
            cache: "no-store",
            ...options,
        });

        let data;

        try {
            data = await response.json();
        } catch {
            throw new Error(`Server returned HTTP ${response.status}.`);
        }

        if (!response.ok || data.success === false) {
            throw new Error(data.message || `Request failed (${response.status}).`);
        }

        return data;
    }

    function setUploadStatus(text, progress = null, error = false) {
        if (!uploadStatus) return;

        uploadStatus.classList.toggle("visible", Boolean(text));
        uploadStatus.classList.toggle("error", error);

        if (text) {
            uploadStatus.querySelector(".upload-status-text").textContent = text;

            if (progress !== null) {
                uploadStatus.querySelector(".upload-progress-bar").style.width = `${Math.max(0, Math.min(100, progress))}%`;
            }
        }
    }

    function renderStorage(storage) {
        if (!storage) return;

        const used = Number(storage.used || 0);
        const limit = Number(storage.limit || 0);
        const percent = limit > 0 ? Math.min(100, (used / limit) * 100) : 0;

        if (storageUsed) storageUsed.textContent = `${formatFileSize(used)} used`;
        if (storageAvailable) storageAvailable.textContent = `${formatFileSize(Math.max(0, limit - used))} available`;
        if (storageProgress) storageProgress.style.width = `${percent}%`;
    }

    function renderFiles() {
        const query = searchInput.value.trim().toLowerCase();

        const visible = files.filter((file) =>
            (file.original_name || "").toLowerCase().includes(query)
        );

        fileCount.textContent = `${visible.length} ${visible.length === 1 ? "file" : "files"}`;
        emptyState.classList.toggle("visible", visible.length === 0);

        filesGrid.innerHTML = visible.map((file) => {
            const type = getFileType(file);
            const name = escapeHtml(file.original_name);
            const size = formatFileSize(Number(file.size));
            const date = escapeHtml(formatDate(file.created_at));

            if (type === "video") {
                return `
                    <article class="file-card" data-id="${file.id}" data-name="${name}">
                        <div class="file-preview video-preview">
                            <div class="preview-gradient"></div>
                            <div class="video-symbol">▶</div>
                            <span class="file-type">VIDEO</span>
                            <button class="preview-action" type="button" data-action="preview">Open</button>
                        </div>
                        <div class="file-info">
                            <div class="file-title-row">
                                <div>
                                    <h3 title="${name}">${name}</h3>
                                    <p>${size} · Video</p>
                                </div>
                                <button class="delete-action" type="button" data-action="delete" aria-label="Delete file" title="Delete file"><span aria-hidden="true">⌫</span><span>Delete</span></button>
                            </div>
                            <div class="file-meta">
                                <span>${date}</span>
                                <div class="file-actions">
                                    <button type="button" class="small-action download-action" data-action="download">↓</button>
                                    <button type="button" class="small-action share-action" data-action="share">↗</button>
                                </div>
                            </div>
                        </div>
                    </article>
                `;
            }

            const previewClass = type === "document" ? "document-preview" : "archive-preview";
            const icon = type === "document" ? "PDF" : type === "archive" ? "ZIP" : "FILE";
            const label = type === "document" ? "PDF" : type === "archive" ? "ZIP" : "FILE";

            return `
                <article class="file-card" data-id="${file.id}" data-name="${name}">
                    <div class="file-preview ${previewClass}">
                        <div class="${type === "document" ? "document-icon" : "archive-icon"}">${icon}</div>
                        <span class="file-type">${label}</span>
                    </div>
                    <div class="file-info">
                        <div class="file-title-row">
                            <div>
                                <h3 title="${name}">${name}</h3>
                                <p>${size} · ${type === "document" ? "Document" : type === "archive" ? "Archive" : "File"}</p>
                            </div>
                            <button class="more-button" type="button" data-action="delete">•••</button>
                        </div>
                        <div class="file-meta">
                            <span>${date}</span>
                            <div class="file-actions">
                                <button type="button" class="small-action download-action" data-action="download">↓</button>
                                <button type="button" class="small-action share-action" data-action="share">↗</button>
                            </div>
                        </div>
                    </div>
                </article>
            `;
        }).join("");
    }

    async function loadFiles() {
        try {
            const data = await request(API);
            files = Array.isArray(data.files) ? data.files : [];
            renderStorage(data.storage);
            renderFiles();
        } catch (error) {
            files = [];
            renderFiles();
            showToast(error.message);
        }
    }

    async function createShare(fileId) {
        const data = await request(SHARE_API, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ file_id: Number(fileId) }),
        });

        return data.url;
    }

    async function shareFile(file) {
        try {
            const url = await createShare(file.id);

            modalFileName.textContent = file.original_name;
            shareLink.value = url;
            shareModal.classList.add("open");

            window.setTimeout(() => {
                shareLink.select();
            }, 50);
        } catch (error) {
            showToast(error.message);
        }
    }

    async function downloadFile(file) {
        try {
            showToast("Preparing download…");
            const url = await createShare(file.id);
            window.location.href = url;
        } catch (error) {
            showToast(error.message);
        }
    }

    async function previewFile(file) {
        try {
            const url = await createShare(file.id);
            window.open(`${url}&inline=1`, "_blank", "noopener,noreferrer");
        } catch (error) {
            showToast(error.message);
        }
    }

    async function deleteFile(file) {
        if (!window.confirm(`Delete "${file.original_name}"?`)) return;

        try {
            await request(`${API}?id=${encodeURIComponent(file.id)}`, {
                method: "DELETE",
            });

            showToast("File deleted.");
            await loadFiles();
        } catch (error) {
            showToast(error.message);
        }
    }

    async function cancelUpload() {
        const upload = activeUpload;
        if (!upload || !upload.uploadId) return;

        upload.cancelled = true;
        upload.controller.abort();

        try {
            await request(API + "?id=" + encodeURIComponent(upload.uploadId), { method: "DELETE" });
        } catch (error) {
            console.warn("Could not clean cancelled upload:", error);
        }

        removeResume(upload.file);
        activeUpload = null;
        setUploadStatus("");
        showToast("Upload cancelled.");
        await loadFiles();
    }

    async function uploadFile(file) {
        if (file.size < 1 || file.size > MAX_FILE_SIZE) {
            showToast(`${file.name}: maximum size is 10 GB.`);
            return;
        }

        const key = `${file.name}:${file.size}:${file.lastModified}`;
        const resumeMap = getResumeMap();
        let uploadId = Number(resumeMap[key] || 0);
        let received = new Set();

        const controller = new AbortController();
        activeUpload = { file, uploadId, controller, cancelled: false };

        const cancelButton = document.getElementById("uploadCancel");
        if (cancelButton) {
            cancelButton.hidden = false;
            cancelButton.disabled = false;
        }

        try {
            if (uploadId) {
                try {
                    const resume = await request(`${API}?action=upload&id=${uploadId}`);
                    received = new Set((resume.upload.received_chunks || []).map(Number));

                    if (resume.upload.status === "completed") {
                        removeResume(file);
                        await loadFiles();
                        return;
                    }
                } catch {
                    uploadId = 0;
                }
            }

            if (!uploadId) {
                const initForm = new FormData();
                initForm.append("action", "init");
                initForm.append("name", file.name);
                initForm.append("size", String(file.size));
                initForm.append("mime_type", file.type || "application/octet-stream");

                const init = await request(API, {
                    method: "POST",
                    body: initForm,
                });

                uploadId = Number(init.upload.id);
                saveResume(file, uploadId);
                activeUpload.uploadId = uploadId;
            }

            const totalChunks = Math.ceil(file.size / CHUNK_SIZE);

            for (let index = 0; index < totalChunks; index++) {
                if (received.has(index)) {
                    setUploadStatus(`Resuming ${file.name}…`, ((index + 1) / totalChunks) * 100);
                    continue;
                }

                const start = index * CHUNK_SIZE;
                const end = Math.min(file.size, start + CHUNK_SIZE);

                let success = false;

                for (let attempt = 1; attempt <= 3 && !success; attempt++) {
                    const form = new FormData();
                    form.append("action", "chunk");
                    form.append("upload_id", String(uploadId));
                    form.append("chunk_index", String(index));
                    form.append("chunk", file.slice(start, end), file.name);

                    try {
                        await request(API, {
                            method: "POST",
                            body: form,
                            signal: controller.signal,
                        });
                        success = true;
                    } catch (error) {
                        if (attempt === 3) throw error;
                        await new Promise((resolve) => setTimeout(resolve, 700 * attempt));
                    }
                }

                setUploadStatus(
                    `Uploading ${file.name} — ${Math.round(((index + 1) / totalChunks) * 100)}%`,
                    ((index + 1) / totalChunks) * 100
                );
            }

            const completeForm = new FormData();
            completeForm.append("action", "complete");
            completeForm.append("upload_id", String(uploadId));
            completeForm.append("chunk_size", String(CHUNK_SIZE));
            completeForm.append("total_chunks", String(totalChunks));

            await request(API, {
                method: "POST",
                body: completeForm,
                signal: controller.signal,
            });

            removeResume(file);
            activeUpload = null;
            setUploadStatus(`${file.name} uploaded successfully.`, 100);
            showToast(`${file.name} uploaded successfully.`);

            await loadFiles();

            window.setTimeout(() => setUploadStatus(""), 1800);
        } catch (error) {
            if (controller.signal.aborted || activeUpload?.cancelled) {
                return;
            }

            saveResume(file, uploadId);
            activeUpload = null;
            setUploadStatus(
                `${file.name}: ${error.message}. You can retry safely.`,
                null,
                true
            );
            showToast(error.message);
        }
    }

    async function handleSelectedFiles(fileList) {
        const selected = Array.from(fileList || []);

        if (!selected.length) return;

        for (const file of selected) {
            await uploadFile(file);
        }
    }

    const uploadCancelButton = document.getElementById("uploadCancel");
    if (uploadCancelButton) {
        uploadCancelButton.addEventListener("click", cancelUpload);
    }

    uploadButton.addEventListener("click", () => fileInput.click());
    browseButton.addEventListener("click", () => fileInput.click());

    fileInput.addEventListener("change", async (event) => {
        await handleSelectedFiles(event.target.files);
        event.target.value = "";
    });

    ["dragenter", "dragover"].forEach((eventName) => {
        uploadZone.addEventListener(eventName, (event) => {
            event.preventDefault();
            event.stopPropagation();
            uploadZone.classList.add("dragging");
        });
    });

    ["dragleave", "drop"].forEach((eventName) => {
        uploadZone.addEventListener(eventName, (event) => {
            event.preventDefault();
            event.stopPropagation();
            uploadZone.classList.remove("dragging");
        });
    });

    uploadZone.addEventListener("drop", async (event) => {
        await handleSelectedFiles(event.dataTransfer?.files);
    });

    searchInput.addEventListener("input", renderFiles);

    document.querySelectorAll(".view-button").forEach((button) => {
        button.addEventListener("click", () => {
            const view = button.dataset.view;

            document.querySelectorAll(".view-button").forEach((item) => {
                item.classList.toggle("active", item === button);
            });

            filesGrid.classList.toggle("list-view", view === "list");
        });
    });

    filesGrid.addEventListener("click", async (event) => {
        const actionButton = event.target.closest("[data-action]");

        if (!actionButton) return;

        const card = actionButton.closest(".file-card");
        const file = files.find((item) => Number(item.id) === Number(card?.dataset.id));

        if (!file) return;

        const action = actionButton.dataset.action;

        if (action === "share") await shareFile(file);
        if (action === "download") await downloadFile(file);
        if (action === "preview") await previewFile(file);
        if (action === "delete") await deleteFile(file);
    });

    function closeShareModal() {
        shareModal.classList.remove("open");
    }

    modalClose.addEventListener("click", closeShareModal);

    shareModal.addEventListener("click", (event) => {
        if (event.target === shareModal) closeShareModal();
    });

    copyLink.addEventListener("click", async () => {
        try {
            await navigator.clipboard.writeText(shareLink.value);
            copyLink.textContent = "Copied";
            showToast("Share link copied.");

            window.setTimeout(() => {
                copyLink.textContent = "Copy";
            }, 1500);
        } catch {
            shareLink.focus();
            shareLink.select();
            document.execCommand("copy");
        }
    });

    function openMobileMenu() {
        sidebar.classList.add("open");
        mobileOverlay.classList.add("visible");
        document.body.style.overflow = "hidden";
    }

    function closeMobileMenu() {
        sidebar.classList.remove("open");
        mobileOverlay.classList.remove("visible");
        document.body.style.overflow = "";
    }

    mobileMenu.addEventListener("click", openMobileMenu);
    mobileOverlay.addEventListener("click", closeMobileMenu);

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
            closeShareModal();
            closeMobileMenu();
        }
    });

    const navItems = Array.from(document.querySelectorAll(".nav-item[data-nav-target]"));

    function setActiveNav(targetId) {
        navItems.forEach((item) => {
            item.classList.toggle("active", item.dataset.navTarget === targetId);
        });
    }

    navItems.forEach((item) => {
        item.addEventListener("click", () => {
            const target = document.getElementById(item.dataset.navTarget);

            if (!target) return;

            setActiveNav(item.dataset.navTarget);
            target.scrollIntoView({ behavior: "smooth", block: "start" });

            if (window.innerWidth <= 700) {
                closeMobileMenu();
            }
        });
    });

    const navSections = navItems
        .map((item) => document.getElementById(item.dataset.navTarget))
        .filter(Boolean);

    if ("IntersectionObserver" in window && navSections.length) {
        const navObserver = new IntersectionObserver(
            (entries) => {
                const visible = entries
                    .filter((entry) => entry.isIntersecting)
                    .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];

                if (visible) {
                    setActiveNav(visible.target.id);
                }
            },
            {
                rootMargin: "-18% 0px -62% 0px",
                threshold: [0.1, 0.35, 0.6],
            }
        );

        navSections.forEach((section) => navObserver.observe(section));
    }

    renderFiles();
    loadFiles();
})();
