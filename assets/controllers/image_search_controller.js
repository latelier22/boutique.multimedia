import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['modal', 'query', 'results'];
  static values  = {
    productId: Number,
    defaultQuery: String     // ← on déclare notre nouveau Value
  };

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

connect() {
    document.addEventListener('paste', this.onPaste.bind(this));
  }
  disconnect() {
    document.removeEventListener('paste', this.onPaste.bind(this));
  }

  // Méthode liée au click du bouton
  pasteImage(event) {
    // on peut juste donner le focus au document pour que le prochain Ctrl+V soit capté
    window.focus();
  }

  onPaste(event) {
    const items = event.clipboardData?.items;
    if (!items) return;

    for (let item of items) {
      if (item.type.startsWith('image/')) {
        const blob = item.getAsFile();
        return this.uploadBlob(blob);
      }
    }
  }

  async uploadBlob(blob) {
    const form = new FormData();
    form.append('file', blob, 'pasted.png');
    await fetch(
      `/admin/ajax/product/${this.productIdValue}/add-image`,
      {
        method: 'POST',
        body: form,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }
    );
    sessionStorage.setItem('syliusActiveTab', 'media');
    location.reload();
  }



}
