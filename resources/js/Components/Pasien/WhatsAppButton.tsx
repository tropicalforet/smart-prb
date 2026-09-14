import { useState } from 'react';
import { PasienPRB } from '@/types';
import { Button } from '../ui/Button';
import { generateWhatsAppLink, getWhatsAppTemplate } from '@/lib/whatsapp-templates';
import { MessageCircle } from 'lucide-react';

interface Props {
    pasien: PasienPRB;
    tanggalEvaluasi?: string;
    variant?: 'icon' | 'full';
}

export function WhatsAppButton({ pasien, tanggalEvaluasi, variant = 'icon' }: Props) {
    const handleWaClick = () => {
        if (!pasien.no_whatsapp) {
            alert('Nomor WhatsApp pasien tidak tersedia.');
            return;
        }

        const message = getWhatsAppTemplate({ pasien, tanggalEvaluasi });
        const link = generateWhatsAppLink(pasien.no_whatsapp, message);
        window.open(link, '_blank');
    };

    if (variant === 'icon') {
        return (
            <button
                onClick={handleWaClick}
                disabled={!pasien.no_whatsapp}
                className="p-1.5 rounded-lg text-emerald-600 hover:bg-emerald-50 disabled:opacity-30 disabled:cursor-not-allowed transition-colors"
                title={pasien.no_whatsapp ? `Hubungi via WhatsApp (${pasien.no_whatsapp})` : 'Nomor WhatsApp tidak tersedia'}
            >
                <MessageCircle className="w-5 h-5" />
            </button>
        );
    }

    return (
        <Button
            onClick={handleWaClick}
            disabled={!pasien.no_whatsapp}
            variant="outline"
            icon={<MessageCircle className="w-4 h-4 text-emerald-600" />}
            className="w-full justify-center"
        >
            Kirim Pesan WhatsApp
        </Button>
    );
}
