import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static values = { breakpoint: Number };

  connect() {
    this.breakpointValue ||= 768;
    this.readState();
    window.addEventListener('resize', () => this.readState());
  }

  readState() {
    let state = sessionStorage.getItem('sylius:layout');
    if (state === null) {
      state = window.innerWidth > this.breakpointValue;
    } else {
      state = window.innerWidth > this.breakpointValue
        ? (state === 'true')
        : false;
    }
    console.log("layout controller", state);
    this.element.classList.toggle('admin-layout--open', state);
    sessionStorage.setItem('sylius:layout', state);
  }
}
