(() => {
    "use strict";

    const uploadButton = document.getElementById("uploadButton");
    const browseButton = document.getElementById("browseButton");
    const fileInput = document.getElementById("fileInput");

    const uploadZone = document.getElementById("uploadZone");

    const searchInput = document.getElementById("searchInput");
    const filesGrid = document.getElementById("filesGrid");
    const fileCount = document.getElementById("fileCount");
    const emptyState = document.getElementById("emptyState");

    const mobileMenu = document.getElementById("mobileMenu");
    const sidebar = document.getElementById("sidebar");
    const mobileOverlay = document.getElementById("mobileOverlay");

    const shareModal = document.getElementById("shareModal");
    const modalClose = document.getElementById("modalClose");
    const modalFileName = document.getElementById("modalFileName");
    const shareLink = document.getElementById("shareLink");
    const copyLink = document.getElementById("copyLink");

    const toastContainer = document.getElementById("toastContainer");

    /*
     * ------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------
     */

    function showToast(message) {
        const toast = document.createElement("div");

        toast.className = "toast";
        toast.textContent = message;

        toastContainer.appendChild(toast);

        window.setTimeout(() => {
            toast.style.opacity = "0";
            toast.style.transform = "translateY(8px)";

            window.setTimeout(() => {
                toast.remove();
            }, 180);
        }, 2600);
    }

    function formatFileSize(bytes) {
        if (!Number.isFinite(bytes) || bytes <= 0) {
            return "0 B";
        }

        const units = ["B", "KB", "MB", "GB", "TB"];
        const index = Math.floor(
            Math.log(bytes) / Math.log(1024)
        );

        const value = bytes / Math.pow(1024, index);

        return `${value.toFixed(value >= 10 || index === 0 ? 0 : 1)} ${units[index]}`;
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
        const mime = file.type || "";
        const extension = file.name
            .split(".")
            .pop()
            .toLowerCase();

        if (mime.startsWith("video/")) {
            return "video";
        }

        if (mime === "application/pdf" || extension === "pdf") {
            return "document";
        }

        if (
            [
                "zip",
                "rar",
                "7z",
                "tar",
                "gz"
            ].includes(extension)
        ) {
            return "archive";
        }

        return "file";
    }

    /*
     * ------------------------------------------------------------
     * Upload UI
     *
     * IMPORTANT:
     * This is only frontend behavior for now.
     * Real 5GB upload will be connected on VPS later.
     * ------------------------------------------------------------
     */

    function handleSelectedFiles(files) {
        if (!files || !files.length) {
            return;
        }

        const selected = Array.from(files);

        selected.forEach((file) => {
            showToast(
                `${file.name} selected — real upload will be enabled on VPS.`
            );
        });
    }

    uploadButton.addEventListener("click", () => {
        fileInput.click();
    });

    browseButton.addEventListener("click", () => {
        fileInput.click();
    });

    fileInput.addEventListener("change", (event) => {
        handleSelectedFiles(event.target.files);

        /*
         * Allow selecting the same file again.
         */
        event.target.value = "";
    });

    [
        "dragenter",
        "dragover"
    ].forEach((eventName) => {
        uploadZone.addEventListener(eventName, (event) => {
            event.preventDefault();
            event.stopPropagation();

            uploadZone.classList.add("dragging");
        });
    });

    [
        "dragleave",
        "drop"
    ].forEach((eventName) => {
        uploadZone.addEventListener(eventName, (event) => {
            event.preventDefault();
            event.stopPropagation();

            uploadZone.classList.remove("dragging");
        });
    });

    uploadZone.addEventListener("drop", (event) => {
        const files = event.dataTransfer?.files;

        if (!files?.length) {
            return;
        }

        handleSelectedFiles(files);
    });

    /*
     * ------------------------------------------------------------
     * Search
     * ------------------------------------------------------------
     */

    function filterFiles() {
        const query = searchInput.value
            .trim()
            .toLowerCase();

        const cards = Array.from(
            filesGrid.querySelectorAll(".file-card")
        );

        let visibleCount = 0;

        cards.forEach((card) => {
            const name = (
                card.dataset.name ||
                card.querySelector("h3")?.textContent ||
                ""
            ).toLowerCase();

            const visible = !query || name.includes(query);

            card.style.display = visible ? "" : "none";

            if (visible) {
                visibleCount += 1;
            }
        });

        fileCount.textContent =
            `${visibleCount} ${visibleCount === 1 ? "file" : "files"}`;

        emptyState.classList.toggle(
            "visible",
            visibleCount === 0
        );
    }

    searchInput.addEventListener("input", filterFiles);

    /*
     * ------------------------------------------------------------
     * Grid / List view
     * ------------------------------------------------------------
     */

    const viewButtons = document.querySelectorAll(".view-button");

    viewButtons.forEach((button) => {
        button.addEventListener("click", () => {
            const view = button.dataset.view;

            viewButtons.forEach((item) => {
                item.classList.toggle(
                    "active",
                    item === button
                );
            });

            filesGrid.classList.toggle(
                "list-view",
                view === "list"
            );
        });
    });

    /*
     * ------------------------------------------------------------
     * Share modal
     * ------------------------------------------------------------
     */

    function openShareModal(fileName) {
        modalFileName.textContent = fileName;

        /*
         * Placeholder for local UI only.
         * Real share token will be generated on VPS.
         */
        const fakeToken = Math.random()
            .toString(36)
            .slice(2, 10);

        shareLink.value =
            `https://drive.example/s/${fakeToken}`;

        shareModal.classList.add("open");

        window.setTimeout(() => {
            shareLink.select();
        }, 50);
    }

    function closeShareModal() {
        shareModal.classList.remove("open");
    }

    document.addEventListener("click", (event) => {
        const shareButton =
            event.target.closest(".share-action");

        if (!shareButton) {
            return;
        }

        const card = shareButton.closest(".file-card");

        if (!card) {
            return;
        }

        const fileName =
            card.querySelector("h3")?.textContent.trim() ||
            "File";

        openShareModal(fileName);
    });

    modalClose.addEventListener(
        "click",
        closeShareModal
    );

    shareModal.addEventListener("click", (event) => {
        if (event.target === shareModal) {
            closeShareModal();
        }
    });

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
            closeShareModal();
            closeMobileMenu();
        }
    });

    /*
     * ------------------------------------------------------------
     * Copy share link
     * ------------------------------------------------------------
     */

    copyLink.addEventListener("click", async () => {
        const value = shareLink.value;

        try {
            await navigator.clipboard.writeText(value);

            copyLink.textContent = "Copied";

            showToast("Share link copied.");

            window.setTimeout(() => {
                copyLink.textContent = "Copy";
            }, 1500);
        } catch {
            shareLink.focus();
            shareLink.select();

            document.execCommand("copy");

            copyLink.textContent = "Copied";

            showToast("Share link copied.");

            window.setTimeout(() => {
                copyLink.textContent = "Copy";
            }, 1500);
        }
    });

    /*
     * ------------------------------------------------------------
     * Download buttons
     *
     * Frontend placeholder only.
     * Real download endpoint will be connected on VPS.
     * ------------------------------------------------------------
     */

    document.addEventListener("click", (event) => {
        const downloadButton =
            event.target.closest(".download-action");

        if (!downloadButton) {
            return;
        }

        const card =
            downloadButton.closest(".file-card");

        const fileName =
            card?.querySelector("h3")?.textContent.trim() ||
            "file";

        showToast(
            `Download for "${fileName}" will be connected on VPS.`
        );
    });

    /*
     * ------------------------------------------------------------
     * Preview buttons
     * ------------------------------------------------------------
     */

    document.addEventListener("click", (event) => {
        const previewButton =
            event.target.closest(".preview-action");

        if (!previewButton) {
            return;
        }

        const card =
            previewButton.closest(".file-card");

        const fileName =
            card?.querySelector("h3")?.textContent.trim() ||
            "Video";

        showToast(
            `Video preview for "${fileName}" will use the VPS file.`
        );
    });

    /*
     * ------------------------------------------------------------
     * More button
     * ------------------------------------------------------------
     */

    document.addEventListener("click", (event) => {
        const moreButton =
            event.target.closest(".more-button");

        if (!moreButton) {
            return;
        }

        const card =
            moreButton.closest(".file-card");

        const fileName =
            card?.querySelector("h3")?.textContent.trim() ||
            "File";

        showToast(
            `Actions for "${fileName}" will be connected later.`
        );
    });

    /*
     * ------------------------------------------------------------
     * Mobile sidebar
     * ------------------------------------------------------------
     */

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

    mobileMenu.addEventListener(
        "click",
        openMobileMenu
    );

    mobileOverlay.addEventListener(
        "click",
        closeMobileMenu
    );

    /*
     * ------------------------------------------------------------
     * Navigation visual state
     * ------------------------------------------------------------
     */

    document
        .querySelectorAll(".nav-item")
        .forEach((item) => {
            item.addEventListener("click", () => {
                document
                    .querySelectorAll(".nav-item")
                    .forEach((navItem) => {
                        navItem.classList.remove("active");
                    });

                item.classList.add("active");

                if (window.innerWidth <= 700) {
                    closeMobileMenu();
                }
            });
        });

    /*
     * ------------------------------------------------------------
     * Initial state
     * ------------------------------------------------------------
     */

    filterFiles();

})();