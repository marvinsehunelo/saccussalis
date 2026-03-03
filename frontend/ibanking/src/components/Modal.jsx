import React from 'react';
import Button from './Button';

const Modal = ({ isOpen, title, children, onClose }) => {
  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
      <div className="bg-gray-800 text-gray-100 p-6 rounded-none w-full max-w-lg border-t-4 border-yellow-500 shadow-xl">
        <div className="flex justify-between items-center mb-4">
          <h3 className="text-2xl font-bold">{title}</h3>
          <Button type="secondary" onClick={onClose}>Close</Button>
        </div>
        <div>{children}</div>
      </div>
    </div>
  );
};

export default Modal;
