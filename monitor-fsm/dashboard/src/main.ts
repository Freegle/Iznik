import { createApp } from 'vue'
import App from './App.vue'
import 'bootstrap'
import 'bootstrap/dist/css/bootstrap.min.css'
import 'bootstrap-icons/font/bootstrap-icons.min.css'

const app = createApp(App)

// Global styles
const style = document.createElement('style')
style.textContent = `
  :root {
    --freegle-green: #20c997;
  }

  body {
    background-color: #f8f9fa;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  }

  .card {
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #dee2e6;
  }

  .table-sm {
    font-size: 0.9rem;
  }

  .btn-group-sm .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.85rem;
  }
`
document.head.appendChild(style)

app.mount('#app')
