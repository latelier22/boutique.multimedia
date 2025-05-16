import { Controller } from '@hotwired/stimulus';
import 'semantic-ui-css/components/modal.js';

export default class extends Controller {
  static targets = ['query', 'results'];

  connect() {
    // Instaure la recherche initiale si besoin
  }

  openModal(event) {
    // Préremplir avec code, nom et description du produit (passés en data-attributes Twig)
    const code  = this.element.dataset.productCode  || '';
    const name  = this.element.dataset.productName  || '';
    const desc  = this.element.dataset.productDesc  || '';
    this.queryTarget.value = [code, name, desc].filter(Boolean).join(' ');
    
    // Ouvrir la modale Semantic UI
    $('#image-search-modal')
      .modal({ autofocus: false, observeChanges: true })
      .modal('show');
    
    this.search(); // lancer la première recherche
  }

  async search() {
    const q = encodeURIComponent(this.queryTarget.value.trim());
    if (!q) return;
    const url = `/admin/image-search?q=${q}`;
    const response = await fetch(url);
    const html = await response.text();
    // On suppose que le template renvoie un fragment HTML contenant <div class="cards">…
    this.resultsTarget.innerHTML = html;
    this._bindResultClicks();
  }

  _bindResultClicks() {
    this.resultsTarget.querySelectorAll('.image-card').forEach(card => {
      card.addEventListener('click', async () => {
        const imageUrl = card.dataset.url;
        await this._associateImage(imageUrl);
        // Refermez la modale
        $('#image-search-modal').modal('hide');
        // Optionnel : rafraîchir la liste des images
        location.reload();
      });
    });
  }

  async _associateImage(url) {
    // Appel AJAX vers un nouvel endpoint qui crée le ProductImage
    const productId = this.element.dataset.productId;
    await fetch(`/admin/ajax/product/${productId}/add-image`, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With':'XMLHttpRequest' },
      body:    JSON.stringify({ url })
    });
  }
}
