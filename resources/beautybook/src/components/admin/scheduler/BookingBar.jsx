import React from 'react';
import { format, parseISO } from 'date-fns';
import { getLeftPositionFromTime, getWidthFromDuration } from '../../../utils/schedulerUtils';

const STATUS_COLORS = {
  PENDING: 'bg-yellow-100 border-yellow-300 text-yellow-800',
  CONFIRMED: 'bg-blue-100 border-blue-300 text-blue-800',
  COMPLETED: 'bg-green-100 border-green-300 text-green-800',
  CANCELLED: 'bg-red-100 border-red-300 text-red-800',
  NO_SHOW: 'bg-gray-100 border-gray-300 text-gray-800',
};

export default function BookingBar({ booking, currentDate, onDragStart, onResizeEnd }) {
  const left = getLeftPositionFromTime(booking.appointmentStartTime, currentDate);
  const width = getWidthFromDuration(booking.appointmentStartTime, booking.appointmentEndTime);
  
  const colorClass = STATUS_COLORS[booking.status] || STATUS_COLORS.PENDING;
  
  const handleDragStart = (e) => {
    e.dataTransfer.setData('text/plain', booking.id);
    e.dataTransfer.effectAllowed = 'move';
    if (onDragStart) onDragStart(booking);
  };

  const handleResizePointerDown = (e) => {
    e.stopPropagation(); // prevent drag
    e.preventDefault();
    
    const startX = e.clientX;
    const startWidth = width;

    const handlePointerMove = (moveEvent) => {
      // Optimistic resize visual update could be done here, 
      // but for simplicity we'll just wait for pointer up.
    };

    const handlePointerUp = (upEvent) => {
      document.removeEventListener('pointermove', handlePointerMove);
      document.removeEventListener('pointerup', handlePointerUp);
      
      const deltaX = upEvent.clientX - startX;
      if (deltaX !== 0 && onResizeEnd) {
        onResizeEnd(booking.id, startWidth + deltaX);
      }
    };

    document.addEventListener('pointermove', handlePointerMove);
    document.addEventListener('pointerup', handlePointerUp);
  };

  return (
    <div
      draggable
      onDragStart={handleDragStart}
      className={`absolute top-2 bottom-2 rounded border shadow-sm flex flex-col p-1 text-xs overflow-hidden cursor-move hover:shadow-md transition-shadow z-10 ${colorClass}`}
      style={{ left: `${left}px`, width: `${width}px` }}
      title={`${booking.customer?.user?.fullName} - ${booking.bookingServices?.[0]?.service?.name}`}
    >
      <div className="font-semibold truncate">
        {format(parseISO(booking.appointmentStartTime), 'HH:mm')} - {format(parseISO(booking.appointmentEndTime), 'HH:mm')}
      </div>
      <div className="truncate font-bold mt-0.5">
        {booking.customer?.user?.fullName}
      </div>
      <div className="truncate text-gray-600 mt-0.5">
        {booking.bookingServices?.[0]?.service?.name}
      </div>

      {/* Resize Handle (Right edge) */}
      <div 
        className="absolute right-0 top-0 bottom-0 w-2 cursor-ew-resize hover:bg-black/10"
        onPointerDown={handleResizePointerDown}
      />
    </div>
  );
}
