import { useState } from 'react';
import { PasienPRB } from '@/types';
import { Button } from '../ui/Button';
import { generateWhatsAppLink, getWhatsAppTemplate } from '@/lib/whatsapp-templates';
import { MessageCircle, AlertCircle } from 'lucide-react';

interface Props {
    pasien: PasienPRB;
    tanggalEvaluasi?: string;
    variant?: 'icon' | 'full';
}

export function WhatsAppButton({ pasien, tanggalEvaluasi, variant = 'icon' }: Props) {
    const hasWhatsApp = !!(pasien.no_whatsapp && pasien.no_whatsapp.length >= 10);

    const handleWaClick = () => {
        if (!hasWhatsApp) {
            alert('❌ Nomor WhatsApp tidak tersedia untuk pasien ini.\n\nPasien ini tidak dapat dihubungi via WhatsApp karena nomor tidak terdaftar dalam sistem.');
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
                disabled={!hasWhatsApp}
                className={`p-1.5 rounded-lg transition-colors ${
                    hasWhatsApp 
                        ? 'text-emerald-600 hover:bg-emerald-50 cursor-pointer' 
                        : 'text-slate-300 cursor-not-allowed'
                }`}
                title={
                    hasWhatsApp 
                        ? `Hubungi via WhatsApp: ${pasien.no_whatsapp}` 
                        : '❌ Nomor WhatsApp tidak tersedia - pasien tidak dapat dihubungi via WhatsApp'
                }
            >
                {hasWhatsApp ? (
                    <MessageCircle className="w-5 h-5" />
                ) : (
                    <AlertCircle className="w-5 h-5" />
                )}
            </button>
        );
    }

    return (
        <Button
            onClick={handleWaClick}
            disabled={!hasWhatsApp}
            variant="outline"
            icon={
                hasWhatsApp ? (
                    <MessageCircle className="w-4 h-4 text-emerald-600" />
                ) : (
                    <AlertCircle className="w-4 h-4 text-slate-400" />
                )
            }
            className="w-full justify-center"
            title={
                hasWhatsApp 
                    ? `Kirim pesan ke ${pasien.no_whatsapp}` 
                    : 'Nomor WhatsApp tidak tersedia untuk pasien ini'
            }
        >
            {hasWhatsApp ? 'Kirim Pesan WhatsApp' : 'Nomor WA Tidak Tersedia'}
        </Button>
    );
}
