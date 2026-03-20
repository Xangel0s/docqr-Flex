<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $documentName }} - DocQR</title>
    <style>
        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            min-height: 100%;
            background: #ffffff;
            font-family: "Segoe UI", Tahoma, sans-serif;
        }

        .viewer-shell {
            width: 100%;
            min-height: 100vh;
            padding: 12px 0 24px;
        }

        .status {
            padding: 20px 16px;
            text-align: center;
            font-size: 14px;
            color: #5b6472;
        }

        .status.error {
            color: #c0392b;
        }

        .page-stack {
            display: grid;
            gap: 16px;
            justify-items: center;
        }

        .page-card {
            position: relative;
            display: inline-block;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            overflow: hidden;
        }

        .page-card canvas {
            display: block;
            max-width: min(100vw - 24px, 100%);
            height: auto;
        }

        .qr-overlay {
            position: absolute;
            z-index: 2;
            object-fit: contain;
            pointer-events: none;
        }

        @media (max-width: 768px) {
            .viewer-shell {
                padding-top: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="viewer-shell">
        <div id="status" class="status">Cargando documento...</div>
        <div id="pages" class="page-stack" hidden></div>
    </div>

    <script type="module">
        import * as pdfjsLib from 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.3.136/pdf.min.mjs';

        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.3.136/pdf.worker.min.mjs';

        const state = {
            pdfUrl: @json($pdfUrl),
            qrImageUrl: @json($qrImageUrl),
            qrPosition: @json($qrPosition),
            standardWidth: 595,
            standardHeight: 842,
        };

        const statusNode = document.getElementById('status');
        const pagesNode = document.getElementById('pages');

        const showStatus = (message, isError = false) => {
            statusNode.textContent = message;
            statusNode.classList.toggle('error', isError);
            statusNode.hidden = false;
        };

        const hideStatus = () => {
            statusNode.hidden = true;
        };

        const renderPage = async (pdf, pageNumber, targetPage) => {
            const page = await pdf.getPage(pageNumber);
            const viewport = page.getViewport({ scale: window.innerWidth < 768 ? 1.15 : 1.45 });

            const card = document.createElement('div');
            card.className = 'page-card';

            const canvas = document.createElement('canvas');
            canvas.width = Math.floor(viewport.width);
            canvas.height = Math.floor(viewport.height);
            card.appendChild(canvas);

            const context = canvas.getContext('2d', { alpha: false });
            await page.render({ canvasContext: context, viewport }).promise;

            if (pageNumber === targetPage && state.qrPosition) {
                const overlay = document.createElement('img');
                overlay.src = state.qrImageUrl;
                overlay.alt = 'Codigo QR';
                overlay.className = 'qr-overlay';

                const scaleX = viewport.width / state.standardWidth;
                const scaleY = viewport.height / state.standardHeight;

                overlay.style.left = `${Number(state.qrPosition.x || 0) * scaleX}px`;
                overlay.style.top = `${Number(state.qrPosition.y || 0) * scaleY}px`;
                overlay.style.width = `${Number(state.qrPosition.width || 0) * scaleX}px`;
                overlay.style.height = `${Number(state.qrPosition.height || 0) * scaleY}px`;

                card.appendChild(overlay);
            }

            pagesNode.appendChild(card);
            pagesNode.hidden = false;
            hideStatus();
        };

        const render = async () => {
            try {
                const loadingTask = pdfjsLib.getDocument({
                    url: state.pdfUrl,
                    withCredentials: false,
                });

                const pdf = await loadingTask.promise;
                const targetPage = Number(state.qrPosition?.page_number || 1);

                for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber += 1) {
                    try {
                        await renderPage(pdf, pageNumber, targetPage);
                    } catch (pageError) {
                        console.error(`Error rendering page ${pageNumber}:`, pageError);
                    }
                }

                if (pagesNode.children.length === 0) {
                    showStatus('No se pudo cargar el documento.', true);
                }
            } catch (error) {
                console.error('Error rendering overlay viewer:', error);

                if (pagesNode.children.length === 0) {
                    showStatus('No se pudo cargar el documento.', true);
                } else {
                    hideStatus();
                    pagesNode.hidden = false;
                }
            }
        };

        render();
    </script>
</body>
</html>
