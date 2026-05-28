import React, { useState, useEffect } from 'react';
import { useAuth } from '../context/AuthContext';
import apiClient from '../api/client';
import FFTChart from '../components/analytics/FFTChart';
import RiskGauge from '../components/analytics/RiskGauge';
import ForecastTimeline from '../components/analytics/ForecastTimeline';
import { FFTAnalysisResponse, ProjectForecastResponse, RiskScoringResponse } from '../types/analytics';

// Project and Task Interfaces for Client CRUD
interface Task {
  id: number;
  project_id: number;
  title: string;
  description: string | null;
  status: string;
  priority: number;
  estimated_hours: number | null;
  actual_hours: number | null;
  due_date: string | null;
  completed_at: string | null;
}

interface Project {
  id: number;
  name: string;
  description: string | null;
  status: string;
  estimated_completion: string | null;
  risk_score: number;
}

// Fully self-contained mock analytical data for fallback presentation if live API has empty databases
const mockFftData: FFTAnalysisResponse = {
  success: true,
  sampling_rate_hours: 1.0,
  signal_length_raw: 720,
  fft_length_padded: 1024,
  dominant_frequency_cycles_day: 0.1428,
  dominant_period_days: 7.0,
  peak_magnitude: 42.6,
  insight: "User exhibits a stable weekly activity cycle (~7.0 days). Weekend vs. weekday productivity patterns are highly rhythmic. FFT spectrum analysis confirms a significant delivery concentration around Monday-Tuesday.",
  spectrum: Array.from({ length: 32 }, (_, i) => {
    const k = i + 1;
    const factor = k === 6 ? 42.6 : k === 12 ? 18.2 : Math.max(1.5, 15 / k + Math.random() * 2);
    return {
      frequency_cycles_day: k * (24.0 / 1024),
      period_days: 1024 / (k * 24.0),
      magnitude: factor,
    };
  }),
};

const mockRiskData: RiskScoringResponse = {
  success: true,
  project_id: 1,
  risk_score: 0.284,
  risk_level: 'LOW (ON TRACK - NOMINAL STATE)',
  linearization_model: 'Jacobian first-order Taylor expansion',
  breakdown: {
    overdue_ratio: { metric_value: 0.15, jacobian_sensitivity: 0.35, risk_contribution: 0.0525, percentage_impact: 18.49 },
    velocity_deficit: { metric_value: 0.32, jacobian_sensitivity: 0.25, risk_contribution: 0.08, percentage_impact: 28.17 },
    priority_density: { metric_value: 0.40, jacobian_sensitivity: 0.15, risk_contribution: 0.06, percentage_impact: 21.13 },
    inactivity_decay: { metric_value: 0.28, jacobian_sensitivity: 0.15, risk_contribution: 0.042, percentage_impact: 14.79 },
    backlog_weight: { metric_value: 0.50, jacobian_sensitivity: 0.10, risk_contribution: 0.05, percentage_impact: 17.61 },
  },
};

const mockForecastData: ProjectForecastResponse = {
  success: true,
  completed_tasks_count: 24,
  incomplete_tasks_count: 18,
  base_velocity: 1.142,
  velocity_drift_coefficient: -0.00345,
  total_estimated_remaining_hours: 142.0,
  forecasted_remaining_days: 15.42,
  estimated_completion_date: new Date(Date.now() + 16 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
  newton_raphson_iterations: 4,
  newton_raphson_converged: true,
  method: 'Newton-Raphson velocity convergence (EWMA-smoothed)',
  confidence: 0.95,
};

export const Dashboard: React.FC = () => {
  const { user, logout } = useAuth();
  const [selectedMetric, setSelectedMetric] = useState<string>('task_completion_rate');
  const [useLiveApi, setUseLiveApi] = useState<boolean>(true); // default to Live API Mode!
  
  // Projects and Tasks States for Real CRUD
  const [projects, setProjects] = useState<Project[]>([]);
  const [selectedProjectId, setSelectedProjectId] = useState<number | null>(null);
  const [tasks, setTasks] = useState<Task[]>([]);

  // Create Project / Task Form States
  const [newProjectName, setNewProjectName] = useState('');
  const [newProjectDesc, setNewProjectDesc] = useState('');
  
  const [newTaskTitle, setNewTaskTitle] = useState('');
  const [newTaskEst, setNewTaskEst] = useState('');
  const [newTaskPriority] = useState('2');
  const [newTaskDueDate, setNewTaskDueDate] = useState('');

  const [taskActualHours, setTaskActualHours] = useState<Record<number, string>>({});
  const [completingTaskId, setCompletingTaskId] = useState<number | null>(null);

  const [fftData, setFftData] = useState<FFTAnalysisResponse>(mockFftData);
  const [riskData, setRiskData] = useState<RiskScoringResponse>(mockRiskData);
  const [forecastData, setForecastData] = useState<ProjectForecastResponse>(mockForecastData);

  // 1. Fetch Projects from backend API
  const fetchProjects = async () => {
    try {
      const res = await apiClient.get<any>('/projects');
      const projectsList = res.data?.data || res.data;
      
      // Strict array validation
      if (Array.isArray(projectsList)) {
        setProjects(projectsList);
        if (projectsList.length > 0 && !selectedProjectId) {
          setSelectedProjectId(projectsList[0].id);
        }
      } else {
        setProjects([]);
      }
    } catch (e) {
      console.warn('Backend API offline or database empty. Using simulations...');
      setProjects([]); // Fallback to safe empty array
      setUseLiveApi(false);
    }
  };

  // 2. Fetch Tasks of Selected Project
  const fetchTasks = async (projId: number) => {
    try {
      const res = await apiClient.get<any>(`/projects/${projId}`);
      
      // Extract the array using res.data.data first, with robust fallback options
      let fetchedTasks = null;
      if (res.data) {
        if (Array.isArray(res.data.data)) {
          fetchedTasks = res.data.data;
        } else if (res.data.tasks) {
          fetchedTasks = Array.isArray(res.data.tasks) ? res.data.tasks : res.data.tasks.data;
        } else if (Array.isArray(res.data)) {
          fetchedTasks = res.data;
        }
      }
      
      // Strict array validation
      if (Array.isArray(fetchedTasks)) {
        setTasks(fetchedTasks);
      } else {
        setTasks([]);
      }
    } catch (e) {
      console.warn('Failed to fetch tasks of project', projId);
      setTasks([]); // Fallback to safe empty array on error
    }
  };

  useEffect(() => {
    fetchProjects();
  }, []);

  useEffect(() => {
    if (selectedProjectId) {
      fetchTasks(selectedProjectId);
    } else {
      setTasks([]);
    }
  }, [selectedProjectId]);

  // 3. Fetch Live Analytics & Forecasts from Backend
  useEffect(() => {
    if (!useLiveApi || !selectedProjectId) {
      setFftData(mockFftData);
      setRiskData(mockRiskData);
      setForecastData(mockForecastData);
      return;
    }

    const fetchLiveAnalytics = async () => {
      // 1. FFT Verisini Çek
      try {
        const fftRes = await apiClient.get<FFTAnalysisResponse>(`/analytics/patterns?metric=${selectedMetric}`);
        // Eğer backend 'success: true' döndüyse veya 'data' null değilse veriyi al, yoksa boş state setle.
        if (fftRes.data.success && fftRes.data.spectrum) {
            setFftData(fftRes.data);
        } else {
             setFftData({...mockFftData, success: false, spectrum: [] }); // Boş state
        }
      } catch (e) {
         setFftData({...mockFftData, success: false, spectrum: [] }); // Hata varsa da boş göster
      }

      // 2. Risk Verisini Çek
      try {
        const riskRes = await apiClient.get<RiskScoringResponse>(`/analytics/risk/${selectedProjectId}`);
        if (riskRes.data.success) {
             setRiskData(riskRes.data);
        } else {
             setRiskData({...mockRiskData, success: false});
        }
      } catch (e) {
         setRiskData({...mockRiskData, success: false});
      }

      // 3. Forecast Verisini Çek
      try {
        const forecastRes = await apiClient.get<ProjectForecastResponse>(`/analytics/forecast/${selectedProjectId}`);
        if (forecastRes.data.success) {
             setForecastData(forecastRes.data);
        } else {
             setForecastData({...mockForecastData, success: false});
        }
      } catch (e) {
         setForecastData({...mockForecastData, success: false});
      }
    };

    fetchLiveAnalytics();
  }, [useLiveApi, selectedMetric, selectedProjectId]);

  // 4. Handle Create Project
  const handleCreateProject = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newProjectName.trim()) return;

    try {
      const res = await apiClient.post<{ project: any }>('/projects', {
        name: newProjectName,
        description: newProjectDesc,
      });
      const newProj = res.data.project?.data || res.data.project;
      
      // Functional state update pattern with strict array validation
      setProjects(prevProjects => {
        const projectsArray = Array.isArray(prevProjects) ? prevProjects : [];
        return [newProj, ...projectsArray];
      });
      
      setSelectedProjectId(newProj.id);
      setNewProjectName('');
      setNewProjectDesc('');
      setUseLiveApi(true); // switch to live mode automatically
    } catch (err) {
      alert('Project could not be created. You may have hit the Leaky Bucket rate limit.');
    }
  };

  // 5. Handle Create Task
  const handleCreateTask = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedProjectId || !newTaskTitle.trim()) return;

    try {
      const res = await apiClient.post<{ task: any }>(`/projects/${selectedProjectId}/tasks`, {
        title: newTaskTitle,
        estimated_hours: newTaskEst ? parseFloat(newTaskEst) : null,
        priority: parseInt(newTaskPriority),
        due_date: newTaskDueDate || null,
      });
      const newTask = res.data.task?.data || res.data.task;
      
      // Functional state update pattern with strict array validation
      setTasks(prevTasks => {
        const tasksArray = Array.isArray(prevTasks) ? prevTasks : [];
        return [newTask, ...tasksArray];
      });

      setNewTaskTitle('');
      setNewTaskEst('');
      setNewTaskDueDate('');
    } catch (err) {
      alert('Task could not be created. The API rate limit may have been exceeded.');
    }
  };

  // 6. Handle Complete Task (Triggers telemetries for forecast calculations)
  const handleCompleteTask = async (taskId: number) => {
    const hours = taskActualHours[taskId];
    if (!hours || parseFloat(hours) < 0) {
      alert('Please enter a valid hours spent value.');
      return;
    }

    try {
      const res = await apiClient.patch<{ task: any }>(`/tasks/${taskId}/complete`, {
        actual_hours: parseFloat(hours),
      });
      
      const updatedTask = res.data.task?.data || res.data.task;
      
      // Functional state update pattern with strict array validation
      setTasks(prevTasks => {
        const tasksArray = Array.isArray(prevTasks) ? prevTasks : [];
        return tasksArray.map(t => t.id === taskId ? updatedTask : t);
      });
      setCompletingTaskId(null);
      
      // Re-trigger projects updates to fetch new forecasts
      if (selectedProjectId) {
        fetchTasks(selectedProjectId);
      }
    } catch (err) {
      alert('Task could not be completed. Check server connection or API rate limits.');
    }
  };

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col font-sans">
      {/* Top Navbar */}
      <header className="border-b border-white/10 bg-slate-900/60 backdrop-blur-md sticky top-0 z-50 px-6 py-4 flex items-center justify-between">
        <div className="flex items-center space-x-3">
          <span className="text-3xl">🔱</span>
          <div>
            <h1 className="text-xl font-bold tracking-tight text-white">APOLLO ENERGY</h1>
            <p className="text-[10px] text-amber-500 font-bold uppercase tracking-widest">
              Asset Management Control Panel
            </p>
          </div>
        </div>

        <div className="flex items-center space-x-6">
          <div className="flex items-center space-x-3">
            <div className="w-9 h-9 rounded-full bg-gradient-to-tr from-amber-500 to-orange-500 flex items-center justify-center font-bold text-slate-900">
              {user?.name ? user.name[0].toUpperCase() : 'A'}
            </div>
            <div className="hidden md:block">
              <p className="text-sm font-semibold text-white leading-none">{user?.name || 'Visitor'}</p>
              <p className="text-[10px] text-white/40 mt-1">{user?.email || 'guest@apollo.com'}</p>
            </div>
          </div>

          <button
            onClick={logout}
            className="px-4 py-2 rounded-lg bg-white/5 hover:bg-rose-500/10 border border-white/10 hover:border-rose-500/20 text-sm text-white/80 hover:text-rose-400 font-medium active:scale-[0.98] transition-all duration-200"
          >
            Log Out
          </button>
        </div>
      </header>

      {/* Main Content Area */}
      <main className="flex-1 p-6 md:p-8 space-y-8 max-w-7xl w-full mx-auto">
        
        {/* Dashboard Title & Mode Switcher */}
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div>
            <h2 className="text-3xl font-extrabold tracking-tight text-white">Analytical Monitoring Dashboard</h2>
            <p className="text-white/40 text-sm mt-1">
              Fast Fourier Transforms, Newton-Raphson velocity convergence, and Jacobian risk analysis
            </p>
          </div>
          
          <div className="flex items-center space-x-3 self-start">
            <span className="text-xs text-white/50">Display Mode:</span>
            <button
              onClick={() => setUseLiveApi(!useLiveApi)}
              className={`px-3 py-1.5 rounded-lg text-xs font-semibold border transition-all duration-200 ${
                !useLiveApi
                  ? 'bg-amber-500/10 border-amber-500/30 text-amber-300'
                  : 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300'
              }`}
            >
              {!useLiveApi ? '📊 Simulation Data' : '🔌 Live API Mode'}
            </button>
          </div>
        </div>

        {/* Global Statistics Cards */}
        <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
          <div className="p-6 rounded-2xl bg-white/5 border border-white/10 shadow-lg">
            <span className="text-white/40 text-xs font-bold uppercase tracking-wider block">Your Projects</span>
            <span className="text-3xl font-extrabold text-white block mt-2">{projects.length} Projects</span>
            <span className="text-emerald-400 text-xs font-medium block mt-1.5">⚡ Database Persisted</span>
          </div>

          <div className="p-6 rounded-2xl bg-white/5 border border-white/10 shadow-lg">
            <span className="text-white/40 text-xs font-bold uppercase tracking-wider block">Selected Task Workload</span>
            <span className="text-3xl font-extrabold text-white block mt-2">{tasks.length} Tasks</span>
            <span className="text-amber-400 text-xs font-medium block mt-1.5">📈 {tasks.filter(t => t.status === 'completed').length} Completed</span>
          </div>

          <div className="p-6 rounded-2xl bg-white/5 border border-white/10 shadow-lg">
            <span className="text-white/40 text-xs font-bold uppercase tracking-wider block">Signal Sample Size</span>
            <span className="text-3xl font-extrabold text-white block mt-2">720 Hours</span>
            <span className="text-white/60 text-xs font-medium block mt-1.5">📈 Uniformly Sampled L2</span>
          </div>

          <div className="p-6 rounded-2xl bg-white/5 border border-white/10 shadow-lg">
            <span className="text-white/40 text-xs font-bold uppercase tracking-wider block">Argon2id Memory Target</span>
            <span className="text-3xl font-extrabold text-emerald-400 block mt-2">64 MB</span>
            <span className="text-white/60 text-xs font-medium block mt-1.5">🔒 Memory-Hard Locked</span>
          </div>
        </div>

        {/* CRUD CONTROLS AREA: Project Creation & Task Listing */}
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
          {/* Projects Column */}
          <div className="p-6 rounded-2xl bg-white/5 border border-white/10 shadow-xl space-y-6">
            <div>
              <h3 className="text-lg font-semibold text-white">Create New Energy Project</h3>
              <p className="text-white/40 text-xs mt-0.5">Register a new energy project for asset monitoring</p>
            </div>
            
            <form onSubmit={handleCreateProject} className="space-y-4">
              <div>
                <input
                  type="text"
                  placeholder="Project Name (e.g., Solar PV Roof Power System)"
                  value={newProjectName}
                  onChange={(e) => setNewProjectName(e.target.value)}
                  className="w-full px-4 py-2.5 rounded-lg bg-white/5 border border-white/10 text-white placeholder-white/30 focus:outline-none focus:ring-2 focus:ring-amber-500/50 transition-all duration-200 text-sm"
                />
              </div>
              <div>
                <textarea
                  placeholder="Project Description..."
                  value={newProjectDesc}
                  onChange={(e) => setNewProjectDesc(e.target.value)}
                  className="w-full px-4 py-2.5 rounded-lg bg-white/5 border border-white/10 text-white placeholder-white/30 focus:outline-none focus:ring-2 focus:ring-amber-500/50 transition-all duration-200 text-sm h-20"
                />
              </div>
              <button
                type="submit"
                className="w-full py-2.5 rounded-lg bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600 text-white font-semibold text-xs active:scale-[0.98] transition-all duration-200"
              >
                Add Project (POST /api/projects)
              </button>
            </form>

            <div className="border-t border-white/10 pt-4">
              <h4 className="font-semibold text-white text-xs mb-3">Your Projects</h4>
              <div className="space-y-2 max-h-48 overflow-y-auto">
                {projects.map((proj) => (
                  <button
                    key={proj.id}
                    onClick={() => setSelectedProjectId(proj.id)}
                    className={`w-full p-3 rounded-lg text-left text-xs border transition-all duration-200 ${
                      selectedProjectId === proj.id
                        ? 'bg-amber-500/10 border-amber-500/50 text-white font-bold'
                        : 'bg-white/5 border-white/10 text-white/70 hover:bg-white/10'
                    }`}
                  >
                    <div className="flex justify-between items-center">
                      <span>{proj.name}</span>
                      <span className="text-[10px] text-white/30">ID: {proj.id}</span>
                    </div>
                  </button>
                ))}
                {projects.length === 0 && (
                  <p className="text-white/40 text-center text-xs">No projects found.</p>
                )}
              </div>
            </div>
          </div>

          {/* Tasks Column */}
          <div className="lg:col-span-2 p-6 rounded-2xl bg-white/5 border border-white/10 shadow-xl space-y-6">
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
              <div>
                <h3 className="text-lg font-semibold text-white">Task Management (Task CRUD)</h3>
                <p className="text-white/40 text-xs mt-0.5">Add and complete tasks for the selected project</p>
              </div>
            </div>

            {selectedProjectId ? (
              <div className="space-y-6">
                {/* Add Task Form */}
                <form onSubmit={handleCreateTask} className="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                  <div className="md:col-span-2">
                    <input
                      type="text"
                      placeholder="Task Title (e.g., Inverter Connection)"
                      value={newTaskTitle}
                      onChange={(e) => setNewTaskTitle(e.target.value)}
                      className="w-full px-4 py-2.5 rounded-lg bg-white/5 border border-white/10 text-white placeholder-white/30 focus:outline-none focus:ring-2 focus:ring-amber-500/50 transition-all duration-200 text-sm"
                    />
                  </div>
                  <div>
                    <input
                      type="number"
                      placeholder="Estimated Hours"
                      value={newTaskEst}
                      onChange={(e) => setNewTaskEst(e.target.value)}
                      className="w-full px-4 py-2.5 rounded-lg bg-white/5 border border-white/10 text-white placeholder-white/30 focus:outline-none focus:ring-2 focus:ring-amber-500/50 transition-all duration-200 text-sm"
                    />
                  </div>
                  <button
                    type="submit"
                    className="w-full py-2.5 rounded-lg bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600 text-white font-semibold text-xs active:scale-[0.98] transition-all duration-200"
                  >
                    Add Task
                  </button>
                </form>

                {/* Tasks Table */}
                <div className="border-t border-white/10 pt-4 overflow-x-auto">
                  <table className="w-full text-left text-xs border-collapse">
                    <thead>
                      <tr className="border-b border-white/10 text-white/50">
                        <th className="py-2">Title</th>
                        <th className="py-2">Priority</th>
                        <th className="py-2">Plan (Est)</th>
                        <th className="py-2">Actual (Act)</th>
                        <th className="py-2">Status</th>
                        <th className="py-2 text-right">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      {tasks.map((task) => (
                        <tr key={task.id} className="border-b border-white/5 hover:bg-white/5 transition-all">
                          <td className="py-3 font-semibold text-white">{task.title}</td>
                          <td className="py-3">
                            <span className={`px-2 py-0.5 rounded text-[10px] ${
                              task.priority >= 4 ? 'bg-rose-500/20 text-rose-300' : 'bg-white/15 text-white'
                            }`}>
                              P{task.priority}
                            </span>
                          </td>
                          <td className="py-3">{task.estimated_hours ? `${task.estimated_hours} hrs` : '-'}</td>
                          <td className="py-3">{task.actual_hours ? `${task.actual_hours} hrs` : '-'}</td>
                          <td className="py-3">
                            <span className={`px-2 py-0.5 rounded text-[10px] ${
                              task.status === 'completed' ? 'bg-emerald-500/20 text-emerald-300' : 'bg-amber-500/20 text-amber-300'
                            }`}>
                              {task.status}
                            </span>
                          </td>
                          <td className="py-3 text-right">
                            {task.status !== 'completed' ? (
                              completingTaskId === task.id ? (
                                <div className="flex items-center justify-end space-x-2">
                                  <input
                                    type="number"
                                    placeholder="Actual Hours"
                                    value={taskActualHours[task.id] || ''}
                                    onChange={(e) => setTaskActualHours({
                                      ...taskActualHours,
                                      [task.id]: e.target.value
                                    })}
                                    className="w-20 px-2 py-1 rounded bg-slate-900 border border-white/20 text-white text-xs focus:outline-none"
                                  />
                                  <button
                                    onClick={() => handleCompleteTask(task.id)}
                                    className="px-2 py-1 rounded bg-emerald-500 hover:bg-emerald-600 text-slate-950 font-bold"
                                  >
                                    OK
                                  </button>
                                  <button
                                    onClick={() => setCompletingTaskId(null)}
                                    className="px-2 py-1 rounded bg-white/10 hover:bg-white/20 text-white"
                                  >
                                    Cancel
                                  </button>
                                </div>
                              ) : (
                                <button
                                  onClick={() => setCompletingTaskId(task.id)}
                                  className="px-3 py-1 rounded bg-white/10 hover:bg-emerald-500/20 hover:text-emerald-300 border border-white/10 transition-colors"
                                >
                                  Complete
                                </button>
                              )
                            ) : (
                              <span className="text-white/40 italic">Completed</span>
                            )}
                          </td>
                        </tr>
                      ))}
                      {tasks.length === 0 && (
                        <tr>
                          <td colSpan={6} className="py-4 text-center text-white/40">
                            No tasks found for this project. Add a task using the form above!
                          </td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </div>
            ) : (
              <p className="text-white/40 text-center py-8">Please select a project from the left or create a new project to start managing tasks.</p>
            )}
          </div>
        </div>

        {/* Core Charts Matrix */}
        {selectedProjectId ? (
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            {/* Column 1 & 2: FFT Pattern Analysis Area */}
            <div className="lg:col-span-2 space-y-8">
              
              {/* Metric Selection Switcher */}
              <div className="flex items-center space-x-3 p-1.5 rounded-xl bg-white/5 border border-white/5 self-start w-fit">
                <button
                  onClick={() => setSelectedMetric('task_completion_rate')}
                  className={`px-4 py-2 rounded-lg text-xs font-semibold transition-all duration-200 ${
                    selectedMetric === 'task_completion_rate'
                      ? 'bg-amber-500 text-slate-950 shadow-md'
                      : 'text-white/60 hover:text-white hover:bg-white/5'
                  }`}
                >
                  Task Completion Rate
                </button>
                <button
                  onClick={() => setSelectedMetric('task_creation_rate')}
                  className={`px-4 py-2 rounded-lg text-xs font-semibold transition-all duration-200 ${
                    selectedMetric === 'task_creation_rate'
                      ? 'bg-amber-500 text-slate-950 shadow-md'
                      : 'text-white/60 hover:text-white hover:bg-white/5'
                  }`}
                >
                  Task Creation Rate
                </button>
              </div>

              <FFTChart data={fftData} />
              
              <ForecastTimeline data={forecastData} />
            </div>

            {/* Column 3: Jacobian Risk Scorer Widget */}
            <div className="lg:col-span-1">
              <RiskGauge data={riskData} />
            </div>
          </div>
        ) : (
          <div className="p-12 rounded-2xl bg-white/5 border border-white/10 text-center space-y-4 shadow-xl backdrop-blur-md">
            <span className="text-5xl block animate-bounce duration-1000">📊</span>
            <h3 className="text-xl font-bold text-white">Select a Project to View Analytical Reports</h3>
            <p className="text-white/40 text-sm max-w-md mx-auto leading-relaxed">
              Real-time Fast Fourier Transforms, EWMA completion timelines, and multi-metric Jacobian risk calculations will be plotted here once a project is active.
            </p>
          </div>
        )}

      </main>

      {/* Footer */}
      <footer className="border-t border-white/5 bg-slate-950/80 px-6 py-6 text-center text-xs text-white/30 mt-auto">
        <p>© 2026 Apollo Global Solutions — Energy Asset Management Platform</p>
        <p className="mt-1 text-[10px]">
          Signal processing and analytical decisions are made using algorithmic efficiency matrices instead of vibe-coding patterns.
        </p>
      </footer>
    </div>
  );
};
export default Dashboard;
