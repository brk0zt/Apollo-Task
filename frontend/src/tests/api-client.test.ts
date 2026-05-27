import { describe, it, expect, beforeEach, vi } from 'vitest';
import apiClient from '../api/client';

describe('Vite API Client Interceptors', () => {
  beforeEach(() => {
    localStorage.clear();
    vi.restoreAllMocks();
  });

  it('should successfully append the Sanctum Bearer token to request headers if present', async () => {
    localStorage.setItem('apollo_token', 'mocked_sanctum_session_token_xyz');

    // Simulate an interceptor request run
    const mockConfig = {
      headers: {} as Record<string, string>,
    };

    // Grab request interceptor from axios instance
    const requestHandler = (apiClient.interceptors.request as any).handlers[0].fulfilled;
    const resolvedConfig = await requestHandler(mockConfig as any);

    expect((resolvedConfig as any).headers.Authorization).toBe('Bearer mocked_sanctum_session_token_xyz');
  });

  it('should clear localStorage and trigger apollo_unauthorized event on 401 response', async () => {
    localStorage.setItem('apollo_token', 'valid_token');
    localStorage.setItem('apollo_user', JSON.stringify({ name: 'Burak' }));

    const dispatchEventSpy = vi.spyOn(window, 'dispatchEvent');

    // Simulate response interceptor error rejection
    const mockError = {
      isAxiosError: true,
      response: {
        status: 401,
        data: { message: 'Unauthenticated.' },
      },
    };

    // Grab response interceptor from axios instance
    const errorHandler = (apiClient.interceptors.response as any).handlers[0].rejected;

    await expect(errorHandler(mockError as any)).rejects.toEqual(mockError);

    // Assert cache clearance
    expect(localStorage.getItem('apollo_token')).toBeNull();
    expect(localStorage.getItem('apollo_user')).toBeNull();

    // Assert custom event dispatching
    expect(dispatchEventSpy).toHaveBeenCalled();
    const eventArg = dispatchEventSpy.mock.calls[0][0] as Event;
    expect(eventArg.type).toBe('apollo_unauthorized');
  });

  it('should trigger apollo_rate_limited custom event on 429 response', async () => {
    const dispatchEventSpy = vi.spyOn(window, 'dispatchEvent');

    const mockError = {
      isAxiosError: true,
      response: {
        status: 429,
        data: {
          message: 'Too Many Requests',
          retry_after: 10,
        },
      },
    };

    // Grab response interceptor from axios instance
    const errorHandler = (apiClient.interceptors.response as any).handlers[0].rejected;

    await expect(errorHandler(mockError as any)).rejects.toEqual(mockError);

    // Assert custom rate limit event dispatching
    expect(dispatchEventSpy).toHaveBeenCalled();
    const eventArg = dispatchEventSpy.mock.calls[0][0] as CustomEvent;
    expect(eventArg.type).toBe('apollo_rate_limited');
    expect(eventArg.detail.message).toBe('Too Many Requests');
    expect(eventArg.detail.retryAfter).toBe(10);
  });
});
