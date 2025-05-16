import '../bootstrap.js'
import '../app.js'


// assets/admin/entry.js
import Routing from 'fos-router';
import routes from './js/fos_js_routes.json';   // CHEMIN DANS assets/

window.Routing = Routing;
Routing.setRoutingData(routes);


// console.log('🟢 admin-perso entry.js chargé');

// document.addEventListener('DOMContentLoaded', () => {
//   const btn = document.getElementById('search-images-btn');
//   if (!btn) {
//     console.warn('⚠️ Bouton search-images-btn non trouvé');
//     return;
//   }
//   btn.addEventListener('click', () => {
//     console.log('🟢 Bouton “Rechercher une image” cliqué');
//   });
// });

import $ from 'jquery';

document.addEventListener('DOMContentLoaded', () => {
  const tab = sessionStorage.getItem('syliusActiveTab');
  if (tab) {
    // On utilise Semantic-UI pour changer la tab
    $(`.ui.menu .item[data-tab="${tab}"]`).tab('change tab', tab);
    // On efface pour le prochain reload
    sessionStorage.removeItem('syliusActiveTab');
  }
});
