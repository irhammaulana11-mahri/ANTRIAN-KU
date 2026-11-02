export function BrandLogo({ className = '', compact = false }: { className?: string; compact?: boolean }) {
  if (compact) {
    return <span className={`flex items-center gap-2 ${className}`}><img src="/assets/antrian-ku-icon.png" alt="" className="h-9 w-9 object-contain" /><span className="font-black tracking-tight">ANTRIAN-KU</span></span>
  }

  return <img src="/assets/antrian-ku-logo.png" alt="ANTRIAN-KU — Efisien | Cepat | Teratur" className={`object-contain object-left ${className}`} />
}
