import './bootstrap';

import Alpine from 'alpinejs';
import { inventoryPage } from './inventory/inventory-page';

window.Alpine = Alpine;
// The inventory page declares x-data="inventoryPage({...})", so the factory has
// to be on window before Alpine walks the DOM.
window.inventoryPage = inventoryPage;

Alpine.start();
