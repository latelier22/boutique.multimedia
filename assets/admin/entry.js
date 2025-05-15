import '../bootstrap.js'
import '../app.js'

// importe le générateur d’URL
import Routing from 'fos-router';
import routes from '../../public/build/admin/js/fos_js_routes.json';

window.Routing = Routing;
Routing.setRoutingData(routes);