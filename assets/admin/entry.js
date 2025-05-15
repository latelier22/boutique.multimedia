import '../bootstrap.js'
import '../app.js'

// assets/admin/entry.js
import Routing from 'fos-router';
import routes from './js/fos_js_routes.json';   // CHEMIN DANS assets/

window.Routing = Routing;
Routing.setRoutingData(routes);