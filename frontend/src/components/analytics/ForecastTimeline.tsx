import React from 'react';
import { ProjectForecastResponse } from '../../types/analytics';

interface ForecastTimelineProps {
  data: ProjectForecastResponse;
}

export const ForecastTimeline: React.FC<ForecastTimelineProps> = ({ data }) => {
  if (!data.success) {
    return (
      <div className="p-6 rounded-2xl bg-white/5 border border-white/10 text-white/60 text-center">
        Project completion forecast could not be calculated.
      </div>
    );
  }

  const confidencePercentage = Math.round(data.confidence * 100);

  return (
    <div className="p-6 rounded-2xl bg-white/5 border border-white/10 shadow-xl backdrop-blur-md">
      <div className="mb-6">
        <h3 className="text-xl font-semibold text-white">Newton-Raphson Completion Forecast</h3>
        <p className="text-white/40 text-sm mt-0.5">
          Time estimation with EWMA velocity curve and nonlinear drift coefficient
        </p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6 items-center">
        {/* Forecast Card */}
        <div className="p-6 rounded-xl bg-gradient-to-br from-amber-500/10 to-orange-500/5 border border-amber-500/20 shadow-lg text-center">
          <span className="text-white/40 text-xs font-semibold uppercase tracking-widest block">
            Projected Completion Date
          </span>
          <span className="text-3xl md:text-4xl font-extrabold text-white block mt-3 tracking-tight">
            {data.estimated_completion_date}
          </span>
          <span className="text-amber-400 text-xs font-medium block mt-2">
            Remaining Time: <strong className="text-white">{(data.forecasted_remaining_days ?? 0).toFixed(1)} Days</strong>
          </span>
          {/* Confidence Indicator */}
          <div className="mt-6 space-y-1.5">
            <div className="flex justify-between items-center text-[10px] text-white/50">
              <span>Model Confidence Index (Confidence)</span>
              <span className="font-bold">{confidencePercentage}%</span>
            </div>
            <div className="w-full h-1.5 rounded-full bg-white/5 overflow-hidden">
              <div
                className="h-full rounded-full bg-gradient-to-r from-amber-500 to-orange-500"
                style={{ width: `${confidencePercentage}%` }}
              />
            </div>
          </div>
        </div>

        {/* Mathematical Model Metrics */}
        <div className="space-y-4">
          <h4 className="font-semibold text-white text-sm">Convergence and Velocity Parameters</h4>
          
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2 gap-3 sm:gap-4">
            <div className="p-3 sm:p-3.5 rounded-lg bg-white/5 border border-white/5 min-w-0 flex flex-col justify-between">
              <span 
                className="text-white/30 text-[10px] uppercase font-bold tracking-wider block break-words whitespace-normal leading-tight"
                title="Adjusted Velocity (v0)"
              >
                Adjusted Velocity ($v_0$)
              </span>
              <span className="text-base sm:text-lg font-bold text-white block mt-1 truncate" title={(data.base_velocity ?? 0).toFixed(3)}>
                {(data.base_velocity ?? 0).toFixed(3)}
              </span>
              <span 
                className="text-white/40 text-[9px] block mt-0.5 break-words whitespace-normal leading-tight" 
                title="Ratio per Task"
              >
                Ratio per Task
              </span>
            </div>

            <div className="p-3 sm:p-3.5 rounded-lg bg-white/5 border border-white/5 min-w-0 flex flex-col justify-between">
              <span 
                className="text-white/30 text-[10px] uppercase font-bold tracking-wider block break-words whitespace-normal leading-tight"
                title="Velocity Drift (alpha)"
              >
                Velocity Drift ($\alpha$)
              </span>
              <span className="text-base sm:text-lg font-bold text-white block mt-1 truncate" title={(data.velocity_drift_coefficient ?? 0).toFixed(5)}>
                {(data.velocity_drift_coefficient ?? 0).toFixed(5)}
              </span>
              <span 
                className="text-white/40 text-[9px] block mt-0.5 break-words whitespace-normal leading-tight" 
                title="Daily Acceleration Change"
              >
                Daily Acceleration Change
              </span>
            </div>

            <div className="p-3 sm:p-3.5 rounded-lg bg-white/5 border border-white/5 min-w-0 flex flex-col justify-between">
              <span 
                className="text-white/30 text-[10px] uppercase font-bold tracking-wider block break-words whitespace-normal leading-tight"
                title="Remaining Workload (R)"
              >
                Remaining Workload ($R$)
              </span>
              <span className="text-base sm:text-lg font-bold text-white block mt-1 truncate" title={`${(data.total_estimated_remaining_hours ?? 0).toFixed(1)} hrs`}>
                {(data.total_estimated_remaining_hours ?? 0).toFixed(1)} hrs
              </span>
              <span 
                className="text-white/40 text-[9px] block mt-0.5 break-words whitespace-normal leading-tight" 
                title="Remaining Planned Time"
              >
                Remaining Planned Time
              </span>
            </div>

            <div className="p-3 sm:p-3.5 rounded-lg bg-white/5 border border-white/5 min-w-0 flex flex-col justify-between">
              <span 
                className="text-white/30 text-[10px] uppercase font-bold tracking-wider block break-words whitespace-normal leading-tight"
                title="Convergence (NR Iter)"
              >
                Convergence (NR Iter)
              </span>
              <span className="text-base sm:text-lg font-bold text-emerald-400 block mt-1 truncate" title={`${data.newton_raphson_iterations || 3} Iterations`}>
                {data.newton_raphson_iterations || 3} Iterations
              </span>
              <span 
                className="text-white/40 text-[9px] block mt-0.5 break-words whitespace-normal leading-tight"
                title={data.newton_raphson_converged ? 'Mathematically Stable' : 'Converging'}
              >
                {data.newton_raphson_converged ? 'Mathematically Stable' : 'Converging'}
              </span>
            </div>
          </div>
        </div>
      </div>

      <div className="mt-6 text-[10px] text-white/40 text-center uppercase tracking-widest bg-white/5 py-2 rounded-lg">
        Forecasting Method: {data.method}
      </div>
    </div>
  );
};
export default ForecastTimeline;
