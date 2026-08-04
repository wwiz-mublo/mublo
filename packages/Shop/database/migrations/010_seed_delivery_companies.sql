-- ====================================
-- Shop Package - 택배사 마스터 + 시드 데이터
-- ====================================

CREATE TABLE IF NOT EXISTS shop_delivery_companies (
    company_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '택배사 ID',

    delivery_method VARCHAR(25) NOT NULL COMMENT '배송 방법 (COURIER/POSTAL/PICKUP)',
    company_name VARCHAR(50) NOT NULL COMMENT '택배사명',
    tracking_url VARCHAR(255) NULL COMMENT '배송 추적 URL',
    callcenter VARCHAR(25) NULL COMMENT '고객센터 번호',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '사용 여부',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='택배사';

-- 시드 데이터: 한국 주요 택배사
INSERT INTO shop_delivery_companies (delivery_method, company_name, tracking_url, callcenter) VALUES
('COURIER', '경동택배', 'https://kdexp.com/basicNewDelivery.kd?barcode=', '080-873-2178'),
('COURIER', '대신택배', 'https://www.ds3211.co.kr/freight/internalFreightSearch.ht?billno=', '043-222-4582'),
('COURIER', '로젠택배', 'https://www.ilogen.com/m/personal/trace.pop/', '1588-9988'),
('COURIER', '우체국', 'https://m.epost.go.kr/postal/mobile/mobile.trace.RetrieveDomRigiTraceList.comm?ems_gubun=E&sid1=', '1588-1300'),
('COURIER', '한진택배', 'https://www.hanjin.co.kr/kor/CMS/DeliveryMgr/WaybillResult.do?mCode=MN038&schLang=KR&wblnumText2=', '1588-0011'),
('COURIER', '롯데택배', 'https://www.lotteglogis.com/open/tracking?invno=', '1588-2121'),
('COURIER', 'CJ대한통운', 'https://www.doortodoor.co.kr/parcel/doortodoor.do?fsp_action=PARC_ACT_002&fsp_cmd=retrieveInvNoACT&invc_no=', '1588-1255'),
('COURIER', 'CVSnet편의점택배', 'https://www.cvsnet.co.kr/invoice/tracking.do?invoice_no=', '1577-1287'),
('COURIER', 'KG옐로우캡택배', 'http://www.yellowcap.co.kr/custom/inquiry_result.asp?invoice_no=', '1588-0123'),
('COURIER', 'KGB택배', 'http://www.kgbls.co.kr/sub5/trace.asp?f_slipno=', '1577-4577'),
('COURIER', 'KG로지스', 'http://www.kglogis.co.kr/contents/waybill.jsp?item_no=', '1588-8848'),
('COURIER', '건영택배', 'https://www.kunyoung.com/goods/goods_01.php?mulno=', '031-460-2700'),
('COURIER', '우리택배', 'http://www.honamlogis.co.kr/page/?pid=tracking_number&SLIP_BARCD=', '031-376-6070');
