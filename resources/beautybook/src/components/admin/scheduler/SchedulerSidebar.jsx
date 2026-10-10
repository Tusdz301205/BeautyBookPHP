import React from 'react';

export default function SchedulerSidebar({ staffList }) {
  return (
    <div className="flex flex-col">
      {/* Header (matches TimelineHeader height) */}
      <div className="h-12 border-b bg-white flex items-center px-4 shrink-0 sticky top-0 z-20">
        <span className="font-semibold text-gray-700">Nhân viên</span>
      </div>

      {/* Staff List */}
      <div className="flex flex-col">
        {staffList.map((staff) => (
          <div 
            key={staff.id} 
            className="h-24 border-b flex items-center px-4 bg-white hover:bg-gray-50 shrink-0"
          >
            {staff.user?.avatarMedia?.url ? <img
              src={staff.user.avatarMedia.url}
              alt={staff.user?.fullName || 'Nhân viên'}
              className="mr-3 h-10 w-10 rounded-full border object-cover"
            /> : <span className="mr-3 grid h-10 w-10 shrink-0 place-items-center rounded-full border bg-pink-50 text-xs font-bold text-pink-700" aria-hidden="true">{(staff.user?.fullName || 'NV').split(/\s+/).filter(Boolean).slice(-2).map((part) => part[0]).join('').toUpperCase()}</span>}
            <div className="flex-1 overflow-hidden">
              <div className="font-medium text-sm text-gray-900 truncate">
                {staff.user?.fullName}
              </div>
              <div className="text-xs text-gray-500 truncate">
                {staff.position || 'Nhân viên'}
              </div>
              <div className="text-xs text-green-600 flex items-center mt-1">
                <span className="w-2 h-2 rounded-full bg-green-500 mr-1"></span>
                Active
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
