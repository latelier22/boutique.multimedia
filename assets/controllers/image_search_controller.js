import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['modal', 'query', 'results'];
  static values  = { productId: Number };

  openModal() {
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
        this.closeModal();
        location.reload();
      });
    });
  }
}
