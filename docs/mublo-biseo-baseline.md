# Mublo Biseo 기준선

> 생성일: 2026-08-08
> 프로젝트 경로: `D:\project\mublo\mublo-biseo`

## Framework 기준

- 원본 저장소: `D:\project\mublo\mublo-public`
- 원본 기본 브랜치: `main`
- 기준 커밋: `b17a373db44b3e1bbb44d0649eab0ac34a94d22c`
- 기준 커밋 제목: `chore: v1.1.0 릴리즈 (#23)`
- Git describe: `v1.0.1-6-gb17a373`
- Framework upstream: `https://github.com/wwiz-mublo/mublo.git`

## 복제 원칙

1. Framework Git 이력을 유지한다.
2. 공개 Framework 원격은 `upstream`으로만 사용한다.
3. Mublo Biseo 전용 원격이 준비되면 `origin`으로 추가한다.
4. Biseo 기능은 우선 `packages/AiAssistant`와 전용 plugin/contract로 확장한다.
5. 운영 `.env`, storage 데이터, 비밀키는 저장소에 넣지 않는다.
6. Framework 기준선을 갱신할 때 이 문서에 새 커밋과 검증결과를 기록한다.

## 최초 복제 상태

- 실제 `.env` 미복사 (`.env.example`만 추적)
- 운영 storage 데이터 미복사 (`.gitkeep` 및 접근설정 파일만 추적)
- `vendor`, `node_modules` 미복사
- 복제 직후에는 Mublo Biseo 전용 기능이 없었으며, 최초 기준 commit에서 `packages/AiAssistant` 수직 기능을 별도 추가
