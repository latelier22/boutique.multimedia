import { Controller } from '@hotwired/stimulus';
import Cropper from 'cropperjs';
import 'cropperjs/dist/cropper.css';

export default class extends Controller {
  static targets = [
    'modal','query','results',
    'previewContainer','preview','size','confirmButton'
  ];
  static values = {
    productId: Number,
    defaultQuery: String
  };

  connect() {
    this.cropper = null;
  }

  openModal() {
    this.queryTarget.value = this.defaultQueryValue || '';
    this.modalTarget.classList.add('active','visible');
    this.queryTarget.focus();
  }

  closeModal() {
    this.modalTarget.classList.remove('active','visible');
  }

  onKeydown(e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      this.search();
    }
  }

  async search() {
    const q = this.queryTarget.value.trim();
    if (!q) return;
    const resp = await fetch(
      `/admin/image-search-ajax?productId=${this.productIdValue}&q=${encodeURIComponent(q)}`,
      { headers: {'X-Requested-With':'XMLHttpRequest'} }
    );
    this.resultsTarget.innerHTML = await resp.text();
    this._bindClicks();
  }

  _bindClicks() {
    this.resultsTarget.querySelectorAll('.image-card').forEach(card => {
      card.addEventListener('click', async () => {
        await fetch(
          `/admin/ajax/product/${this.productIdValue}/add-image`,
          {
            method: 'POST',
            headers: {
              'Content-Type':'application/json',
              'X-Requested-With':'XMLHttpRequest'
            },
            body: JSON.stringify({url: card.dataset.url})
          }
        );
        sessionStorage.setItem('syliusActiveTab','media');
        this.closeModal();
        location.reload();
      });
    });
  }
async pasteImage() {
    if (!navigator.clipboard?.read) {
      return console.error('[ImageSearch] Clipboard.read() non supporté');
    }
    try {
      const items = await navigator.clipboard.read();
      for (const item of items) {
        const type = item.types.find(t => t.startsWith('image/'));
        if (!type) continue;
        const blob = await item.getType(type);
        return this._showCropper(blob);
      }
      console.warn('[ImageSearch] Pas d’image dans le presse-papier');
    } catch (e) {
      console.error('[ImageSearch] Erreur Clipboard.read()', e);
    }
  }

  _showCropper(blob) {
    const url = URL.createObjectURL(blob);
    this.previewTarget.src = url;
    this.sizeTarget.textContent = this._formatBytes(blob.size);
    this.confirmButtonTarget.disabled = false;
    this.previewContainerTarget.style.display = 'block';

    // Si on avait déjà un cropper, on le détruit
    if (this.cropper) {
      this.cropper.destroy();
    }

    // Attendre que l’image soit bien chargée dans le DOM
    this.previewTarget.onload = () => {
      this.cropper = new Cropper(this.previewTarget, {
        // aspectRatio: 1,
        viewMode: 1,
        autoCropArea: 1,
        background: false,
        movable: true,
        zoomable: true,
        cropBoxResizable: true,
      });
    };
  }

  async confirmPaste() {
    if (!this.cropper) return console.error('Cropper non initialisé');
    // Récupère le canevas rogné
    const canvas = this.cropper.getCroppedCanvas();
    const blob   = await new Promise(res => canvas.toBlob(res));
    const file   = new File([blob], 'cropped.png', { type: blob.type });

    const form = new FormData();
    form.append('file', file);
    form.append('url', '');

    const res = await fetch(
      `/admin/ajax/product/${this.productIdValue}/add-image`,
      {
        method: 'POST',
        body:   form,
        headers:{ 'X-Requested-With':'XMLHttpRequest' }
      }
    );
    if (res.ok) {
      this.closeModal();
      location.reload();
    } else {
      console.error('Upload échoué', await res.text());
    }
  }

  _formatBytes(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    const kb = bytes/1024;
    if (kb < 1024) return `${kb.toFixed(1)} KB`;
    return `${(kb/1024).toFixed(1)} MB`;
  }
}
