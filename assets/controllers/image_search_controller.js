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
    defaultQuery: String,
    imageId: Number,
    imageUrl: String
  };

  connect() {
    this.cropper = null;
    this.editingImageId = null; // pas en Values, juste interne
    console.log('image-search connecté');
  
  // 🔥 Corrige le cas du bouton "Éditer" injecté après le DOM initial
  document.querySelectorAll('[data-action~="image-search#editImage"]').forEach(button => {
    button.addEventListener('click', this.editImage.bind(this));
  });
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


/**
   * Chargement de l'image existante pour édition
   */
  async editImage(event) {
  const button = event.currentTarget;
  const id  = button.dataset.imageSearchImageIdValue;
  const url = button.dataset.imageSearchImageUrlValue;
  this.editingImageId = id;
  console.log('Edit image', id, url);
  

  try {
    const response = await fetch(url);
    const blob = await response.blob();
    this._showCropper(blob);
  } catch (e) {
    console.error('[ImageSearch] Impossible de charger l’image existante', e);
  }
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
  if (this.confirmInProgress) return; // 🔒 déjà en cours
  this.confirmInProgress = true;
  this.confirmButtonTarget.disabled = true;

  if (!this.cropper) {
    console.error('Pas de cropper initialisé');
    this._resetConfirmButton();
    return;
  }

  try {
    // 1) Supprimer l’image précédente si édition
    if (this.editingImageId) {
      const delRes = await fetch(
        `/admin/ajax/product/${this.productIdValue}/remove-image/${this.editingImageId}`,
        {
          method: 'DELETE',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }
      );
      if (!delRes.ok) {
        console.error('Suppression échouée', await delRes.text());
        this._resetConfirmButton();
        return;
      }
    }

    // 2) Obtenir le blob depuis le cropper
    const canvas = this.cropper.getCroppedCanvas();
    const blob = await new Promise(resolve => canvas.toBlob(resolve));

    if (!blob) {
      console.error('Erreur : toBlob a retourné null');
      this._resetConfirmButton();
      return;
    }

    const file = new File([blob], 'edited.png', { type: blob.type });

    // 3) Envoyer le fichier
    const form = new FormData();
    form.append('file', file);
    form.append('url', '');

    const res = await fetch(
      `/admin/ajax/product/${this.productIdValue}/add-image`,
      {
        method: 'POST',
        body: form,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }
    );

    if (res.ok) {
      sessionStorage.setItem('syliusActiveTab', 'media');
      location.reload();
    } else {
      console.error('Upload échoué', await res.text());
      this._resetConfirmButton();
    }
  } catch (e) {
    console.error('Erreur pendant le traitement', e);
    this._resetConfirmButton();
  }
}

_resetConfirmButton() {
  this.confirmInProgress = false;
  this.confirmButtonTarget.disabled = false;
}

  _formatBytes(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    const kb = bytes/1024;
    if (kb < 1024) return `${kb.toFixed(1)} KB`;
    return `${(kb/1024).toFixed(1)} MB`;
  }
}
