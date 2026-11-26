// Axios is configured globally so every request carries the XHR header
// Laravel uses to recognise AJAX calls (CSRF is handled via cookie).
import axios from 'axios';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
