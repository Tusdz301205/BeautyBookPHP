import React, { useRef } from 'react';
import BookingBar from './BookingBar';
import { 
  TIMELINE_START_HOUR, 
  TIMELINE_END_HOUR, 
  PIXELS_PER_MINUTE,
  snapPixels,
  getTimeFromLeftPosition,
  getMinutesFromPixels
} from '../../../utils/schedulerUtils';
import { addMinutes, parseISO } from 'date-fns';

export default function SchedulerTimeline({ staffList, bookings, currentDate, onBookingUpdate }) {
  const containerRef = useRef(null);
  
  const totalHours = TIMELINE_END_HOUR - TIMELINE_START_HOUR;
  const hourWidth = 60 * PIXELS_PER_MINUTE; // 120px by default
  const totalWidth = totalHours * hourWidth;

  const hours = Array.from({ length: totalHours }, (_, i) => TIMELINE_START_HOUR + i);

  const handleDragOver = (e) => {
    e.preventDefault(); // allow drop
    e.dataTransfer.dropEffect = 'move';
  };

  const handleDrop = (e, staffId) => {
    e.preventDefault();
    const bookingId = e.dataTransfer.getData('text/plain');
    if (!bookingId) return;

    const rect = containerRef.current.getBoundingClientRect();
    // Vị trí X so với timeline container, cộng với scroll left
    const x = e.clientX - rect.left + containerRef.current.scrollLeft;
    
    // Snap theo block 15 phút
    const snappedX = snapPixels(x, 15);
    const newStartTime = getTimeFromLeftPosition(snappedX, currentDate);
    
    // Tính newEndTime bằng cách giữ nguyên duration
    const booking = bookings.find(b => b.id === bookingId);
    if (!booking) return;

    const durationMins = (new Date(booking.appointmentEndTime) - new Date(booking.appointmentStartTime)) / 60000;
    const newEndTime = addMinutes(parseISO(newStartTime), durationMins).toISOString();

    onBookingUpdate(bookingId, newStartTime, newEndTime, staffId);
  };

  const handleResizeEnd = (bookingId, newWidthPixels) => {
    const booking = bookings.find(b => b.id === bookingId);
    if (!booking) return;
    
    // Snap width
    const snappedWidth = snapPixels(newWidthPixels, 15);
    const addedMins = getMinutesFromPixels(snappedWidth);
    
    // Minimum 15 mins
    const validMins = Math.max(15, addedMins);
    const newEndTime = addMinutes(parseISO(booking.appointmentStartTime), validMins).toISOString();
    
    const staffId = booking.bookingServices?.[0]?.staffId;
    onBookingUpdate(bookingId, booking.appointmentStartTime, newEndTime, staffId);
  };

  return (
    <div className="flex flex-col min-w-max h-full" ref={containerRef}>
      {/* Header (Time slots) */}
      <div 
        className="h-12 border-b bg-white flex sticky top-0 z-10 shrink-0"
        style={{ width: totalWidth }}
      >
        {hours.map(hour => (
          <div 
            key={hour} 
            className="border-r border-gray-200 flex flex-col justify-end text-xs text-gray-500 pb-1 pl-1"
            style={{ width: hourWidth }}
          >
            {`${hour.toString().padStart(2, '0')}:00`}
          </div>
        ))}
      </div>

      {/* Grid Body */}
      <div className="flex flex-col flex-1" style={{ width: totalWidth }}>
        {staffList.map((staff, index) => {
          // Lấy booking của staff này
          const staffBookings = bookings.filter(b => b.bookingServices?.[0]?.staffId === staff.id);
          
          return (
            <div 
              key={staff.id} 
              className="h-24 border-b border-gray-100 flex relative hover:bg-gray-50/50 transition-colors bg-white group"
              onDragOver={handleDragOver}
              onDrop={(e) => handleDrop(e, staff.id)}
            >
              {/* Background grid lines */}
              <div className="absolute inset-0 flex pointer-events-none">
                {hours.map(hour => (
                  <div key={hour} className="h-full border-r border-gray-200 flex-shrink-0" style={{ width: hourWidth }}>
                    <div className="h-full border-r border-gray-100 border-dashed w-1/2"></div>
                  </div>
                ))}
              </div>

              {/* Render Bookings */}
              {staffBookings.map(booking => (
                <BookingBar 
                  key={booking.id} 
                  booking={booking} 
                  currentDate={currentDate}
                  onResizeEnd={handleResizeEnd}
                />
              ))}
            </div>
          );
        })}
      </div>
    </div>
  );
}
