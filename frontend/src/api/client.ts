/// <reference types="vite/client" />
import axios from 'axios';

// Resolve backend API URL from environment variables
const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api';

export const apiClient = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
});

// Request Interceptor: Inject Sanctum Bearer token dynamically
apiClient.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('apollo_token');
    if (token && config.headers) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  },
  (error) => {
    return Promise.reject(error);
  }
);

// Response Interceptor: Catch global 401 (Unauthorized) and 429 (Rate Limit Exceeded)
apiClient.interceptors.response.use(
  (response) => response,
  (error) => {
    if (axios.isAxiosError(error)) {
      const status = error.response?.status;

      if (status === 401) {
        // Token has expired or is invalid -> clear storage and trigger reload/redirect
        localStorage.removeItem('apollo_token');
        localStorage.removeItem('apollo_user');
        
        // Dispatch custom event to let AuthContext know it needs to clean up state
        window.dispatchEvent(new Event('apollo_unauthorized'));
      }

      if (status === 429) {
        // Leaky Bucket Rate Limit Exceeded
        console.warn('Leaky Bucket Rate Limit Exceeded! Refilling tokens...', error.response?.data);
        
        // Dispatch global custom event for rate limit visual alerts
        window.dispatchEvent(
          new CustomEvent('apollo_rate_limited', {
            detail: {
              message: error.response?.data?.message || 'Rate limit exceeded.',
              retryAfter: error.response?.data?.retry_after || 5,
            },
          })
        );
      }
    }
    return Promise.reject(error);
  }
);
export default apiClient;
