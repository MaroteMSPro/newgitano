// Audio Recorder for CRM Luxom - Version 2 con MutationObserver
// Inyecta un botón de grabación junto al input de mensajes

(function() {
    'use strict';

    // Configuración
    const RECORDER_CONFIG = {
        audioMimeType: 'audio/webm;codecs=opus',
        maxDuration: 5 * 60 * 1000, // 5 minutos máximo
        visualizerBars: 32
    };

    class AudioRecorder {
        constructor() {
            this.mediaRecorder = null;
            this.audioChunks = [];
            this.isRecording = false;
            this.isPaused = false;
            this.startTime = null;
            this.timerInterval = null;
            this.audioContext = null;
            this.analyser = null;
            this.visualizerInterval = null;
            this.stream = null;
            this.recordingDuration = 0;
            this.onRecordingUpdate = null;
            this.onRecordingStop = null;
        }

        async start() {
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                this.mediaRecorder = new MediaRecorder(this.stream, {
                    mimeType: RECORDER_CONFIG.audioMimeType
                });

                this.audioChunks = [];
                this.mediaRecorder.ondataavailable = (event) => {
                    if (event.data.size > 0) {
                        this.audioChunks.push(event.data);
                    }
                };

                this.mediaRecorder.onstop = () => {
                    this._onStop();
                };

                this.mediaRecorder.start(100); // Capturar chunks cada 100ms
                this.isRecording = true;
                this.isPaused = false;
                this.startTime = Date.now();
                this.recordingDuration = 0;

                // Timer visual
                this.timerInterval = setInterval(() => {
                    if (!this.isPaused) {
                        this.recordingDuration = Date.now() - this.startTime;
                        if (this.onRecordingUpdate) {
                            this.onRecordingUpdate(this.recordingDuration);
                        }
                    }
                }, 100);

                // Inicializar visualizador
                this._initVisualizer();

                return true;
            } catch (err) {
                console.error('Error al acceder al micrófono:', err);
                alert('No se pudo acceder al micrófono. Asegurate de permitir el acceso.');
                return false;
            }
        }

        pause() {
            if (this.mediaRecorder && this.isRecording && !this.isPaused) {
                this.mediaRecorder.pause();
                this.isPaused = true;
                if (this.visualizerInterval) {
                    clearInterval(this.visualizerInterval);
                    this.visualizerInterval = null;
                }
                return true;
            }
            return false;
        }

        resume() {
            if (this.mediaRecorder && this.isRecording && this.isPaused) {
                this.mediaRecorder.resume();
                this.isPaused = false;
                this._initVisualizer();
                return true;
            }
            return false;
        }

        stop() {
            if (this.mediaRecorder && this.isRecording) {
                this.mediaRecorder.stop();
                this.isRecording = false;
                this.isPaused = false;
                if (this.timerInterval) {
                    clearInterval(this.timerInterval);
                    this.timerInterval = null;
                }
                if (this.visualizerInterval) {
                    clearInterval(this.visualizerInterval);
                    this.visualizerInterval = null;
                }
                if (this.stream) {
                    this.stream.getTracks().forEach(track => track.stop());
                }
                return true;
            }
            return false;
        }

        getAudioBlob() {
            if (this.audioChunks.length === 0) return null;
            return new Blob(this.audioChunks, { type: RECORDER_CONFIG.audioMimeType });
        }

        _onStop() {
            if (this.onRecordingStop) {
                const blob = this.getAudioBlob();
                this.onRecordingStop(blob, this.recordingDuration);
            }
        }

        _initVisualizer() {
            if (!this.stream || !this.audioContext) {
                this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
                this.analyser = this.audioContext.createAnalyser();
                this.analyser.fftSize = 256;
                const source = this.audioContext.createMediaStreamSource(this.stream);
                source.connect(this.analyser);
            }

            if (this.visualizerInterval) {
                clearInterval(this.visualizerInterval);
            }

            this.visualizerInterval = setInterval(() => {
                if (!this.isPaused && this.analyser) {
                    const dataArray = new Uint8Array(this.analyser.frequencyBinCount);
                    this.analyser.getByteFrequencyData(dataArray);
                    // Podríamos actualizar un canvas visualizador aquí
                }
            }, 100);
        }
    }

    // UI Manager
    class RecorderUI {
        constructor() {
            this.recorder = new AudioRecorder();
            this.container = null;
            this.recordButton = null;
            this.pauseButton = null;
            this.cancelButton = null;
            this.sendButton = null;
            this.timerDisplay = null;
            this.visualizer = null;
            this.isUIAttached = false;
            this.currentLeadId = null;
            this.micButton = null;
        }

        attachToChat() {
            // Buscar el contenedor del input de mensajes
            const inputContainer = this._findMessageInputContainer();
            if (!inputContainer) {
                console.warn('No se encontró el contenedor de input de mensajes');
                return false;
            }

            // Crear UI
            this.container = this._createUI();
            inputContainer.parentNode.insertBefore(this.container, inputContainer.nextSibling);

            // Configurar eventos
            this._setupEvents();
            this.isUIAttached = true;

            // Obtener leadId actual de la URL o del estado
            this.currentLeadId = this._extractLeadId();

            // Agregar botón de micrófono al input
            this._addMicButton(inputContainer);
            return true;
        }

        _findMessageInputContainer() {
            // Selectores comunes para inputs de chat
            const selectors = [
                'textarea',
                'input[type="text"]',
                '.message-input',
                '.chat-input',
                '#message-input',
                '[data-testid="message-input"]',
                'form textarea',
                'form input[type="text"]'
            ];

            for (const sel of selectors) {
                const el = document.querySelector(sel);
                if (el) return el.closest('div') || el.parentElement;
            }

            // Fallback: buscar cualquier textarea
            return document.querySelector('textarea')?.parentElement;
        }

        _extractLeadId() {
            // Extraer ID de lead de la URL (ej: /crm/leads/123)
            const match = window.location.pathname.match(/\/leads\/(\d+)/);
            return match ? match[1] : null;
        }

        _createUI() {
            const container = document.createElement('div');
            container.className = 'audio-recorder-ui';
            container.style.cssText = `
                display: none;
                background: #f0f0f0;
                border-radius: 20px;
                padding: 12px 16px;
                margin-top: 10px;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
                border: 1px solid #ddd;
            `;

            // Timer
            const timer = document.createElement('div');
            timer.className = 'recorder-timer';
            timer.textContent = '00:00';
            timer.style.cssText = 'font-family: monospace; font-size: 16px; font-weight: bold; color: #333;';
            this.timerDisplay = timer;

            // Botones
            this.recordButton = this._createButton('⏺️ Grabar', 'record');
            this.pauseButton = this._createButton('⏸️ Pausar', 'pause');
            this.cancelButton = this._createButton('❌ Cancelar', 'cancel');
            this.sendButton = this._createButton('📤 Enviar', 'send');

            // Visualizador (simple)
            const viz = document.createElement('div');
            viz.className = 'visualizer';
            viz.style.cssText = 'height: 20px; width: 100px; background: #ccc; border-radius: 10px; overflow: hidden;';
            this.visualizer = viz;

            container.appendChild(timer);
            container.appendChild(this.recordButton);
            container.appendChild(this.pauseButton);
            container.appendChild(this.cancelButton);
            container.appendChild(this.sendButton);
            container.appendChild(viz);

            this._updateButtonStates();

            return container;
        }

        _createButton(text, action) {
            const btn = document.createElement('button');
            btn.textContent = text;
            btn.dataset.action = action;
            btn.style.cssText = `
                padding: 6px 12px;
                border: none;
                border-radius: 16px;
                background: #007bff;
                color: white;
                cursor: pointer;
                font-size: 14px;
                transition: background 0.2s;
            `;
            btn.addEventListener('mouseenter', () => btn.style.background = '#0056b3');
            btn.addEventListener('mouseleave', () => {
                if (!btn.disabled) btn.style.background = '#007bff';
            });
            return btn;
        }

        _addMicButton(inputContainer) {
            if (this.micButton) return;
            const micButton = document.createElement('button');
            micButton.innerHTML = '🎤';
            micButton.title = 'Grabar audio';
            micButton.style.cssText = `
                background: transparent;
                border: none;
                font-size: 20px;
                cursor: pointer;
                margin-left: 8px;
                padding: 4px;
                border-radius: 50%;
            `;
            micButton.addEventListener('click', (e) => {
                e.preventDefault();
                // Mostrar/ocultar UI de grabación
                this.container.style.display = this.container.style.display === 'none' ? 'flex' : 'none';
            });
            inputContainer.appendChild(micButton);
            this.micButton = micButton;
        }

        _setupEvents() {
            this.recordButton.addEventListener('click', () => this._onRecord());
            this.pauseButton.addEventListener('click', () => this._onPause());
            this.cancelButton.addEventListener('click', () => this._onCancel());
            this.sendButton.addEventListener('click', () => this._onSend());

            this.recorder.onRecordingUpdate = (duration) => {
                this._updateTimer(duration);
            };

            this.recorder.onRecordingStop = (blob, duration) => {
                this._onRecordingComplete(blob, duration);
            };
        }

        _onRecord() {
            if (!this.recorder.isRecording) {
                this.recorder.start().then(success => {
                    if (success) {
                        this.container.style.display = 'flex';
                        this._updateButtonStates();
                    }
                });
            } else {
                this.recorder.stop();
            }
        }

        _onPause() {
            if (this.recorder.isRecording) {
                if (this.recorder.isPaused) {
                    this.recorder.resume();
                    this.pauseButton.textContent = '⏸️ Pausar';
                } else {
                    this.recorder.pause();
                    this.pauseButton.textContent = '▶️ Continuar';
                }
                this._updateButtonStates();
            }
        }

        _onCancel() {
            if (this.recorder.isRecording) {
                this.recorder.stop();
            }
            this.container.style.display = 'none';
            this._updateButtonStates();
            this.timerDisplay.textContent = '00:00';
        }

        async _onSend() {
            if (!this.recorder.isRecording && this.recorder.audioChunks.length > 0) {
                const blob = this.recorder.getAudioBlob();
                await this._uploadAudio(blob);
                this._onCancel();
            }
        }

        _updateTimer(durationMs) {
            const totalSeconds = Math.floor(durationMs / 1000);
            const minutes = Math.floor(totalSeconds / 60).toString().padStart(2, '0');
            const seconds = (totalSeconds % 60).toString().padStart(2, '0');
            this.timerDisplay.textContent = `${minutes}:${seconds}`;
        }

        _updateButtonStates() {
            const { isRecording, isPaused } = this.recorder;
            this.recordButton.textContent = isRecording ? '⏹️ Detener' : '⏺️ Grabar';
            this.recordButton.disabled = false;
            this.pauseButton.disabled = !isRecording;
            this.cancelButton.disabled = !isRecording;
            this.sendButton.disabled = !isRecording || isPaused || this.recorder.audioChunks.length === 0;
        }

        async _uploadAudio(blob) {
            if (!this.currentLeadId) {
                alert('No se pudo identificar el lead actual. Navegá a un chat específico.');
                return;
            }

            // Convertir blob a base64
            const reader = new FileReader();
            reader.readAsDataURL(blob);
            reader.onloadend = () => {
                const base64Audio = reader.result.split(',')[1];
                const payload = {
                    lead_id: this.currentLeadId,
                    tipo: 'audio',
                    contenido: base64Audio,
                    mimetype: 'audio/webm'
                };

                // Enviar al endpoint de mensajes
                fetch('/api/messages/send', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + localStorage.getItem('token')
                    },
                    body: JSON.stringify(payload)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert('Error al enviar audio: ' + data.error);
                    } else {
                        console.log('Audio enviado:', data);
                        // Recargar mensajes o notificar éxito
                    }
                })
                .catch(err => {
                    console.error('Error upload:', err);
                    alert('Error de conexión');
                });
            };
        }

        _onRecordingComplete(blob, duration) {
            this._updateButtonStates();
            // Mostrar preview del audio?
            console.log('Grabación completada', { duration, size: blob.size });
        }
    }

    // Inicialización con MutationObserver
    function init() {
        if (!navigator.mediaDevices || !window.MediaRecorder) {
            console.warn('Audio recording no soportado en este navegador');
            return;
        }

        const ui = new RecorderUI();
        let attached = false;

        function tryAttach() {
            if (!attached && ui.attachToChat()) {
                attached = true;
                console.log('Audio recorder UI attached');
            }
        }

        // Observar cambios en el DOM para cuando Vue renderice el chat
        const observer = new MutationObserver((mutations) => {
            for (const mutation of mutations) {
                if (mutation.addedNodes.length > 0) {
                    // Verificar si apareció un textarea
                    const hasTextarea = document.querySelector('textarea');
                    if (hasTextarea && !attached) {
                        tryAttach();
                    }
                }
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });

        // Intentar inmediatamente por si ya está cargado
        setTimeout(tryAttach, 1000);
    }

    // Iniciar cuando el DOM esté listo
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();