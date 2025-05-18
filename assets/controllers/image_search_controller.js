import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['modal', 'query', 'results'];
  static values = {
    productId: Number,
    defaultQuery: String
  };

  static targets = [
    'modal',
    'query',
    'results',
    'previewContainer',
    'preview',
    'size',
    'confirmButton'
  ];

  openModal() {
    this.queryTarget.value = this.defaultQueryValue || '';
    this.modalTarget.classList.add('active', 'visible');
    this.queryTarget.focus();
  }

  closeModal() {
    this.modalTarget.classList.remove('active', 'visible');
  }

  onKeydown(event) {
    if (event.key === 'Enter') {
      event.preventDefault();
      this.search();
    }
  }

connect() {
    console.log('image-search #connect', {
      targets: this.constructor.targets
    });
  }



  async search() {
    const q = this.queryTarget.value.trim();
    if (!q) return;
    const resp = await fetch(
      `/admin/image-search-ajax?productId=${this.productIdValue}&q=${encodeURIComponent(q)}`,
      {
    headers: {
      'X-Requested-With': 'XMLHttpRequest'
    }
  }
    );
    const html = await resp.text();
    this.resultsTarget.innerHTML = html;
    this._bindClicks();
  }

  _bindClicks() {
    this.resultsTarget.querySelectorAll('.image-card').forEach(card => {
      card.addEventListener('click', async () => {
        const url = card.dataset.url;
        await fetch(
          `/admin/ajax/product/${this.productIdValue}/add-image`,
          {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body:    JSON.stringify({ url })
          }
        );
       // On se souvient qu'on veut rester sur "media"
      sessionStorage.setItem('syliusActiveTab', 'media');

      this.closeModal();
      location.reload();
      });
    });
  }

// ————————————
  // **NOUVEAU** : préparer le collage
  // ————————————
// Méthode appelée sur le bouton « Coller une image »
  async pasteImage() {
    if (!navigator.clipboard || !navigator.clipboard.read) {
      return console.error('[ImageSearch] Clipboard.read() non supporté');
    }
    try {
      const items = await navigator.clipboard.read();
      for (const item of items) {
        // Trouver la première entrée image/…
        const type = item.types.find(t => t.startsWith('image/'));
        if (!type) continue;
        const blob = await item.getType(type);
        this.handleFile(blob);
        return;
      }
      console.warn('[ImageSearch] Pas d’image dans le presse-papier');
    } catch (e) {
      console.error('[ImageSearch] Erreur Clipboard.read()', e);
    }
  }

  // Affiche la preview, la taille et active le bouton valider
  handleFile(blob) {
    const url  = URL.createObjectURL(blob);
    this.previewTarget.src = url;
    this.sizeTarget.textContent = this.formatBytes(blob.size);
    this.confirmButtonTarget.disabled = false;
    this.previewContainerTarget.style.display = 'block';
  }

  // (optionnel) formatter la taille
  formatBytes(bytes) {
    if (bytes < 1024) return bytes + ' B';
    const kb = bytes/1024;
    if (kb < 1024) return kb.toFixed(1) + ' KB';
    return (kb/1024).toFixed(1) + ' MB';
  }

  // Quand on clique sur « Valider l’image »
  async confirmPaste() {
    const blobUrl = this.previewTarget.src;
    // Transformer en fichier réel (fetch+File) ou stocker blobUrl
    const response = await fetch(blobUrl);
    const blob     = await response.blob();
    const file     = new File([blob], 'pasted-image.png', { type: blob.type });

    // Construire FormData pour l’upload
    const form = new FormData();
    form.append('file', file);
    form.append('url', ''); // ou champ url vide si non utilisé

    // POST vers votre route Symfony AJAX
    const res = await fetch(
      `/admin/ajax/product/${this.productIdValue}/add-image`,
      {
        method: 'POST',
        body:   form,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }
    );
    if (res.ok) {
      this.closeModal();
      location.reload();
    } else {
      console.error('Upload échoué', await res.text());
    }
  }

}
