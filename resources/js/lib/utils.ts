import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export const localDate = (iso: string) => new Date(iso + 'T00:00:00').toLocaleDateString();
